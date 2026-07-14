import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import YAML from 'yaml';

const root = process.cwd();
const readJson = (relativePath) => JSON.parse(fs.readFileSync(path.join(root, relativePath), 'utf8'));

const envelope = readJson('contracts/realtime/envelope.schema.json');
const clientSchema = readJson('contracts/realtime/client-message.schema.json');
const serverSchema = readJson('contracts/realtime/server-message.schema.json');
// The envelope schemas deliberately compose required properties across allOf
// branches. Keep AJV's strict checks enabled while allowing that valid pattern.
const realtimeAjv = new Ajv2020({ allErrors: true, strict: true, strictRequired: false });
addFormats(realtimeAjv);
realtimeAjv.addSchema(envelope);
const validateClient = realtimeAjv.compile(clientSchema);
const validateServer = realtimeAjv.compile(serverSchema);

let validRealtime = 0;
let invalidRealtime = 0;
for (const [fixturePath, validate] of [
  ['contracts/fixtures/realtime/client-messages.json', validateClient],
  ['contracts/fixtures/realtime/server-messages.json', validateServer],
]) {
  for (const message of readJson(fixturePath)) {
    if (!validate(message)) {
      throw new Error(`${fixturePath} contains an invalid fixture: ${realtimeAjv.errorsText(validate.errors)}`);
    }
    validRealtime += 1;
  }
}
for (const [fixturePath, validate] of [
  ['contracts/fixtures/realtime/invalid-client-messages.json', validateClient],
  ['contracts/fixtures/realtime/invalid-server-messages.json', validateServer],
]) {
  for (const testCase of readJson(fixturePath)) {
    if (validate(testCase.message)) {
      throw new Error(`${fixturePath} accepted invalid case: ${testCase.reason}`);
    }
    invalidRealtime += 1;
  }
}

const openapi = YAML.parse(fs.readFileSync(path.join(root, 'contracts/http/openapi.yaml'), 'utf8'));
const operations = new Map();
for (const pathItem of Object.values(openapi.paths)) {
  for (const method of ['get', 'post', 'put', 'patch', 'delete']) {
    const operation = pathItem[method];
    if (operation?.operationId) operations.set(operation.operationId, operation);
  }
}

const resolvePointer = (document, pointer) => {
  if (!pointer.startsWith('#/')) throw new Error(`Only local OpenAPI references are supported: ${pointer}`);
  return pointer.slice(2).split('/').reduce((value, token) => value[token.replaceAll('~1', '/').replaceAll('~0', '~')], document);
};
const dereferenceResponse = (response) => response.$ref ? resolvePointer(openapi, response.$ref) : response;
const rewriteRefs = (value) => {
  if (Array.isArray(value)) return value.map(rewriteRefs);
  if (value === null || typeof value !== 'object') return value;
  return Object.fromEntries(Object.entries(value).map(([key, nested]) => [
    key,
    key === '$ref' && typeof nested === 'string'
      ? nested.replace('#/components/schemas/', '#/$defs/')
      : rewriteRefs(nested),
  ]));
};
const definitions = rewriteRefs(openapi.components.schemas);
const httpIndex = readJson('contracts/fixtures/http/index.json');
const httpValidators = new Map();
let validHttp = 0;
for (const [caseName, fixtureName] of Object.entries(httpIndex)) {
  let schema;
  if (caseName === 'error') {
    schema = { $ref: '#/$defs/ErrorResponse' };
  } else {
    const [operationId, explicitStatus] = caseName.split(':');
    const operation = operations.get(operationId);
    if (!operation) throw new Error(`Unknown OpenAPI operationId in fixture index: ${operationId}`);
    const status = explicitStatus ?? Object.keys(operation.responses).find((key) => /^2\d\d$/.test(key));
    if (!status) throw new Error(`No successful response for ${operationId}`);
    const response = dereferenceResponse(operation.responses[status]);
    schema = response.content?.['application/json']?.schema;
    if (!schema) throw new Error(`No JSON response schema for ${operationId}:${status}`);
  }
  const document = {
    $schema: 'https://json-schema.org/draft/2020-12/schema',
    $defs: definitions,
    ...rewriteRefs(schema),
  };
  const httpAjv = new Ajv2020({ allErrors: true, strict: false });
  addFormats(httpAjv);
  const validate = httpAjv.compile(document);
  httpValidators.set(caseName, validate);
  const fixture = readJson(`contracts/fixtures/http/${fixtureName}`);
  if (!validate(fixture)) {
    throw new Error(`${fixtureName} violates ${caseName}: ${httpAjv.errorsText(validate.errors)}`);
  }
  validHttp += 1;
}

let invalidHttp = 0;
for (const [caseName, fixturePath] of [
  ['getVehicle', 'contracts/fixtures/http/invalid-vehicle-responses.json'],
  ['getStation', 'contracts/fixtures/http/invalid-station-responses.json'],
  ['getStationDepartures', 'contracts/fixtures/http/invalid-station-departures-responses.json'],
]) {
  const validate = httpValidators.get(caseName);
  if (!validate) throw new Error(`${caseName} response validator was not compiled`);
  for (const testCase of readJson(fixturePath)) {
    if (validate(testCase.message)) {
      throw new Error(`${path.basename(fixturePath)} accepted invalid case: ${testCase.reason}`);
    }
    invalidHttp += 1;
  }
}

console.log(`Contracts valid: ${validRealtime} realtime fixtures accepted, ${invalidRealtime} invalid realtime fixtures rejected, ${validHttp} HTTP fixtures accepted, ${invalidHttp} invalid HTTP fixtures rejected.`);
