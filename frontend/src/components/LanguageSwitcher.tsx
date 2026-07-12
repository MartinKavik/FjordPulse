import type { Component } from "solid-js";
import { useI18n, type Language } from "../state/i18n";

const choices: readonly { readonly language: Language; readonly label: string; readonly name: string }[] = [
  { language: "nb", label: "NO", name: "Norsk" },
  { language: "en", label: "EN", name: "English" },
];

export const LanguageSwitcher: Component<{ readonly class?: string }> = (props) => {
  const i18n = useI18n();
  return (
    <div class={`language-switcher ${props.class ?? ""}`} role="group" aria-label={i18n.text({ nb: "Språk", en: "Language" })}>
      {choices.map((choice) => (
        <button
          type="button"
          class={i18n.language() === choice.language ? "is-selected" : ""}
          aria-pressed={i18n.language() === choice.language}
          aria-label={i18n.text(
            choice.language === "nb"
              ? { nb: "Bytt språk til norsk", en: "Switch language to Norwegian" }
              : { nb: "Bytt språk til engelsk", en: "Switch language to English" },
          )}
          title={choice.name}
          onClick={() => i18n.setLanguage(choice.language)}
        >
          <span lang={choice.language}>{choice.label}</span>
        </button>
      ))}
    </div>
  );
};
