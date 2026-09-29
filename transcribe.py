"""Transcribe an audio file from the command line, without running the API (fully offline).

Usage:
    python transcribe.py <audio_path> [--model small] [--language en] [--vad]

Writes <audio_name>.txt to the transcripts/ folder.
"""
import argparse
from pathlib import Path

from app.transcriber import transcribe


def main():
    parser = argparse.ArgumentParser(description="Transcribe audio to text")
    parser.add_argument("audio", help="Path to audio file (ogg, mp3, m4a, wav, mp4...)")
    parser.add_argument("--model", default="small", help="tiny | base | small | medium | large-v3")
    parser.add_argument("--language", default=None, help="Language code, e.g. en, hi, ur (auto-detect if omitted)")
    parser.add_argument("--vad", action="store_true", help="Skip silence with VAD filter")
    args = parser.parse_args()

    audio_path = Path(args.audio)
    if not audio_path.exists():
        raise SystemExit(f"File not found: {audio_path}")

    result = transcribe(str(audio_path), args.model, args.language, vad=args.vad)
    print(f"Detected language: {result.language} ({result.language_probability:.0%}), duration: {result.duration:.0f}s")

    lines = [f"[{s['start']:6.1f}s -> {s['end']:6.1f}s] {s['text']}" for s in result.segments]
    print("\n".join(lines))

    out_dir = Path(__file__).parent / "transcripts"
    out_dir.mkdir(exist_ok=True)
    out_file = out_dir / f"{audio_path.stem}.txt"
    out_file.write_text("\n".join(lines), encoding="utf-8")
    print(f"\nSaved transcript to {out_file}")


if __name__ == "__main__":
    main()
