"""Render transcript segments as WebVTT or SRT subtitles."""
from enum import Enum


class SubtitleFormat(str, Enum):
    vtt = "vtt"
    srt = "srt"


MEDIA_TYPES = {SubtitleFormat.vtt: "text/vtt; charset=utf-8", SubtitleFormat.srt: "application/x-subrip; charset=utf-8"}


def _timestamp(seconds: float, decimal_sep: str) -> str:
    ms = round(max(seconds, 0) * 1000)
    hours, ms = divmod(ms, 3_600_000)
    minutes, ms = divmod(ms, 60_000)
    secs, ms = divmod(ms, 1000)
    return f"{hours:02d}:{minutes:02d}:{secs:02d}{decimal_sep}{ms:03d}"


def to_vtt(segments: list[dict]) -> str:
    cues = ["WEBVTT", ""]
    for seg in segments:
        # "&" and "<" have markup meaning in WebVTT cue text.
        text = seg["text"].replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
        cues += [f"{_timestamp(seg['start'], '.')} --> {_timestamp(seg['end'], '.')}", text, ""]
    return "\n".join(cues)


def to_srt(segments: list[dict]) -> str:
    cues = []
    for i, seg in enumerate(segments, start=1):
        cues += [str(i), f"{_timestamp(seg['start'], ',')} --> {_timestamp(seg['end'], ',')}", seg["text"], ""]
    return "\n".join(cues)


def render(segments: list[dict], fmt: SubtitleFormat) -> str:
    return to_vtt(segments) if fmt == SubtitleFormat.vtt else to_srt(segments)
