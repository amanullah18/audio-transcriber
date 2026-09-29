from faster_whisper.tokenizer import _LANGUAGE_CODES

from app.languages import LANGUAGES, Language


def test_language_list_matches_faster_whisper():
    # Fails if a faster-whisper upgrade adds or removes languages, so the API never accepts one the model rejects.
    assert set(LANGUAGES) == set(_LANGUAGE_CODES)


def test_language_names_are_unique():
    assert len(set(LANGUAGES.values())) == len(LANGUAGES)


def test_language_enum_resolves_codes_and_names():
    assert Language("urdu").code == "ur"
    assert Language("ur") is Language("urdu")
    assert Language(" English ").code == "en"
    assert Language("yue").value == "cantonese"
