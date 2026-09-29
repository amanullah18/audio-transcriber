"""Thin wrapper around faster-whisper, shared by the worker and the CLI."""
from dataclasses import dataclass, field
from functools import lru_cache

from faster_whisper import WhisperModel


@dataclass
class TranscriptionResult:
    language: str
    language_probability: float
    duration: float
    segments: list[dict] = field(default_factory=list)

    @property
    def text(self) -> str:
        return " ".join(s["text"] for s in self.segments)


@lru_cache(maxsize=2)
def load_model(name: str) -> WhisperModel:
    # Loading a model takes seconds and hundreds of MB, so keep it cached for the process lifetime.
    return WhisperModel(name, device="cpu", compute_type="int8")


def transcribe(audio_path: str, model_name: str, language: str | None = None, vad: bool = False) -> TranscriptionResult:
    model = load_model(model_name)
    segments, info = model.transcribe(audio_path, language=language, vad_filter=vad)
    return TranscriptionResult(
        language=info.language,
        language_probability=info.language_probability,
        duration=info.duration,
        # `segments` is a lazy generator; the actual decoding happens while iterating.
        segments=[{"start": round(s.start, 2), "end": round(s.end, 2), "text": s.text.strip()} for s in segments],
    )
