import { createContext, createSignal, useContext, type Accessor, type Component, type JSX } from "solid-js";

export type Language = "nb" | "en";

export const DEFAULT_LANGUAGE: Language = "nb";
export const LANGUAGE_STORAGE_KEY = "fjordpulse.locale.v1";

export interface LocalizedText {
  readonly nb: string;
  readonly en: string;
}

export type TranslationValues = Readonly<Record<string, string | number>>;

type LanguageStorage = Pick<Storage, "getItem" | "setItem">;

function browserStorage(): LanguageStorage | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

export function isLanguage(value: unknown): value is Language {
  return value === "nb" || value === "en";
}

export function readLanguage(storage: LanguageStorage | null = browserStorage()): Language {
  if (storage === null) return DEFAULT_LANGUAGE;
  try {
    const stored = storage.getItem(LANGUAGE_STORAGE_KEY);
    return isLanguage(stored) ? stored : DEFAULT_LANGUAGE;
  } catch {
    return DEFAULT_LANGUAGE;
  }
}

export function rememberLanguage(language: Language, storage: LanguageStorage | null = browserStorage()): void {
  if (storage === null) return;
  try {
    storage.setItem(LANGUAGE_STORAGE_KEY, language);
  } catch {
    // The in-memory selection remains usable when browser storage is unavailable.
  }
}

export function languageLocale(language: Language): "nb-NO" | "en-GB" {
  return language === "nb" ? "nb-NO" : "en-GB";
}

export function localize(language: Language, message: LocalizedText, values: TranslationValues = {}): string {
  return message[language].replace(/\{([A-Za-z0-9_]+)\}/g, (placeholder, key: string) => {
    const value = values[key];
    return value === undefined ? placeholder : String(value);
  });
}

interface I18nValue {
  readonly language: Accessor<Language>;
  readonly locale: Accessor<"nb-NO" | "en-GB">;
  readonly setLanguage: (language: Language) => void;
  readonly text: (message: LocalizedText, values?: TranslationValues) => string;
}

const defaultI18n: I18nValue = {
  language: () => DEFAULT_LANGUAGE,
  locale: () => languageLocale(DEFAULT_LANGUAGE),
  setLanguage: () => undefined,
  text: (message, values) => localize(DEFAULT_LANGUAGE, message, values),
};

const I18nContext = createContext<I18nValue>(defaultI18n);

function applyDocumentLanguage(language: Language): void {
  if (typeof document !== "undefined") document.documentElement.lang = language;
}

export const I18nProvider: Component<{
  readonly children: JSX.Element;
  readonly initialLanguage?: Language;
}> = (props) => {
  const [language, setLanguageSignal] = createSignal<Language>(props.initialLanguage ?? readLanguage());
  applyDocumentLanguage(language());

  const setLanguage = (next: Language) => {
    setLanguageSignal(next);
    applyDocumentLanguage(next);
    rememberLanguage(next);
  };

  const value: I18nValue = {
    language,
    locale: () => languageLocale(language()),
    setLanguage,
    text: (message, values) => localize(language(), message, values),
  };

  return <I18nContext.Provider value={value}>{props.children}</I18nContext.Provider>;
};

export function useI18n(): I18nValue {
  return useContext(I18nContext);
}
