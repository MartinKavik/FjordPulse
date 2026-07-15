import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import YAML from 'yaml';

const [operationId, status] = process.argv.slice(2);
if (!operationId || !status) {
  throw new Error('Usage: validate-openapi-response.mjs <operationId> <status>');
}

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const openapi = YAML.parse(fs.readFileSync(path.join(root, 'contracts/http/openapi.yaml'), 'utf8'));

const resolvePointer = (document, pointer) => {
  if (!pointer.startsWith('#/')) {
    throw new Error(`Only local OpenAPI references are supported: ${pointer}`);
  }

  return pointer.slice(2).split('/').reduce(
    (value, token) => value[token.replaceAll('~1', '/').replaceAll('~0', '~')],
    document,
  );
};

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

let operation;
for (const pathItem of Object.values(openapi.paths)) {
  for (const method of ['get', 'post', 'put', 'patch', 'delete']) {
    if (pathItem[method]?.operationId === operationId) {
      operation = pathItem[method];
    }
  }
}
if (!operation) throw new Error(`Unknown OpenAPI operationId: ${operationId}`);

let response = operation.responses[status] ?? operation.responses.default;
if (!response) throw new Error(`${operationId} does not declare response status ${status}`);
if (response.$ref) response = resolvePointer(openapi, response.$ref);

const schema = response.content?.['application/json']?.schema;
if (!schema) throw new Error(`${operationId}:${status} has no application/json response schema`);

const document = {
  $schema: 'https://json-schema.org/draft/2020-12/schema',
  $defs: rewriteRefs(openapi.components.schemas),
  ...rewriteRefs(schema),
};
const ajv = new Ajv2020({ allErrors: true, strict: false });
addFormats(ajv);
const validate = ajv.compile(document);
const chunks = [];
for await (const chunk of process.stdin) chunks.push(chunk);
const payload = JSON.parse(Buffer.concat(chunks).toString('utf8'));

if (!validate(payload)) {
  process.stderr.write(`${operationId}:${status} response violates OpenAPI: ${ajv.errorsText(validate.errors, { separator: '\n' })}\n`);
  process.exit(1);
}
