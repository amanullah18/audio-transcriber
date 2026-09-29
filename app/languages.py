"""Languages supported by Whisper, exposed to clients by readable name (e.g. "urdu") instead of ISO code."""
from enum import Enum

# ISO code -> name, as defined by Whisper. Kept in sync with faster-whisper by tests/test_languages.py.
LANGUAGES: dict[str, str] = {
    "en": "english", "zh": "chinese", "de": "german", "es": "spanish", "ru": "russian",
    "ko": "korean", "fr": "french", "ja": "japanese", "pt": "portuguese", "tr": "turkish",
    "pl": "polish", "ca": "catalan", "nl": "dutch", "ar": "arabic", "sv": "swedish",
    "it": "italian", "id": "indonesian", "hi": "hindi", "fi": "finnish", "vi": "vietnamese",
    "he": "hebrew", "uk": "ukrainian", "el": "greek", "ms": "malay", "cs": "czech",
    "ro": "romanian", "da": "danish", "hu": "hungarian", "ta": "tamil", "no": "norwegian",
    "th": "thai", "ur": "urdu", "hr": "croatian", "bg": "bulgarian", "lt": "lithuanian",
    "la": "latin", "mi": "maori", "ml": "malayalam", "cy": "welsh", "sk": "slovak",
    "te": "telugu", "fa": "persian", "lv": "latvian", "bn": "bengali", "sr": "serbian",
    "az": "azerbaijani", "sl": "slovenian", "kn": "kannada", "et": "estonian", "mk": "macedonian",
    "br": "breton", "eu": "basque", "is": "icelandic", "hy": "armenian", "ne": "nepali",
    "mn": "mongolian", "bs": "bosnian", "kk": "kazakh", "sq": "albanian", "sw": "swahili",
    "gl": "galician", "mr": "marathi", "pa": "punjabi", "si": "sinhala", "km": "khmer",
    "sn": "shona", "yo": "yoruba", "so": "somali", "af": "afrikaans", "oc": "occitan",
    "ka": "georgian", "be": "belarusian", "tg": "tajik", "sd": "sindhi", "gu": "gujarati",
    "am": "amharic", "yi": "yiddish", "lo": "lao", "uz": "uzbek", "fo": "faroese",
    "ht": "haitian_creole", "ps": "pashto", "tk": "turkmen", "nn": "nynorsk", "mt": "maltese",
    "sa": "sanskrit", "lb": "luxembourgish", "my": "myanmar", "bo": "tibetan", "tl": "tagalog",
    "mg": "malagasy", "as": "assamese", "tt": "tatar", "haw": "hawaiian", "ln": "lingala",
    "ha": "hausa", "ba": "bashkir", "jw": "javanese", "su": "sundanese", "yue": "cantonese",
}
_CODES_BY_NAME = {name: code for code, name in LANGUAGES.items()}


class _LanguageBase(str, Enum):
    @property
    def code(self) -> str:
        return _CODES_BY_NAME[self.value]

    @classmethod
    def _missing_(cls, value):
        # Also accept ISO codes ("ur") and any casing ("Urdu"), so API clients can use either form.
        if isinstance(value, str):
            value = value.strip().lower()
            name = LANGUAGES.get(value, value)
            if name in _CODES_BY_NAME:
                return cls(name)
        return None


# Built from the dict so the list lives in one place; sorted so the Swagger dropdown is alphabetical.
Language = _LanguageBase("Language", [(name, name) for name in sorted(_CODES_BY_NAME)])


def language_name(code: str | None) -> str | None:
    return LANGUAGES.get(code) if code else None
