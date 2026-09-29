FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements.txt .
RUN pip install -r requirements.txt

# Run as a non-root user; pre-create dirs that get mounted as volumes so they are owned by it.
RUN useradd --create-home --uid 1000 app \
    && mkdir -p /app/data/uploads /home/app/.cache/huggingface \
    && chown -R app:app /app /home/app/.cache
USER app

COPY --chown=app:app app ./app

EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
