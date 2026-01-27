# API Platform RAG ChatBot

A Retrieval-Augmented Generation (RAG) chatbot for API Platform documentation, powered by Symfony and Ollama.

## Overview

This prototype demonstrates how to build a local AI-powered chatbot that answers questions about API Platform using only official documentation as context. No cloud APIs required - everything runs locally via Docker.

**Tech Stack:**
- Backend: Symfony 7.4 (PHP 8.2+)
- LLM: Mistral 7B (via Ollama)
- Embeddings: Nomic Embed Text (via Ollama)
- Database: PostgreSQL 16 + pgvector extension
- Frontend: Twig + TailwindCSS + Server-Sent Events

## Prerequisites

- Docker & Docker Compose
- Minimum 16GB RAM (8GB allocated to Docker recommended)

## Quick Start

### 1. Start the stack

```bash
docker compose up -d --build
```

### 2. Download AI models

```bash
# Embedding model
docker exec -it chatbot-ollama-1 ollama pull nomic-embed-text

# Chat model
docker exec -it chatbot-ollama-1 ollama pull mistral
```

### 3. Clone documentation sources

```bash
git clone https://github.com/api-platform/docs.git docs
git clone https://github.com/api-platform/core.git core
```

### 4. Create database tables

```bash
php bin/console doctrine:migrations:migrate
```

### 5. Run your first benchmark

```bash
php bin/console app:benchmark --reindex
```

This will:
- Index documentation into 3 separate vector stores (docs, code, combined)
- Run 10 test questions across all 3 sources
- Generate a CSV report with scores (0-5) for each answer

### 6. Use the chatbot

Open your browser: `http://127.0.0.1:8000`

## How It Works

### RAG Pipeline

1. **Ingestion**: Documents are split into chunks and converted to 768-dimensional vectors
2. **Storage**: Vectors are stored in PostgreSQL with pgvector extension
3. **Retrieval**: User questions are vectorized and matched against stored chunks using cosine similarity
4. **Generation**: Top 3 relevant chunks are sent to Mistral with strict instructions to only answer based on provided context

### Benchmarking System

The benchmark compares 3 documentation sources:

- **docs**: Markdown documentation only
- **code**: PHP functional tests + custom fixtures
- **combined**: Everything merged

Results include:
- Score (0-5) evaluated by an AI judge
- Response time in milliseconds
- Category breakdown (basic, code, advanced, security, testing, trap)

### Commands

```bash
# Index specific documentation
php bin/console app:ingest docs/ --target=docs --clear

# Run benchmark (uses existing data)
php bin/console app:benchmark

# Force re-indexing before benchmark
php bin/console app:benchmark --reindex

# Add custom files to index
# Create files_to_index.txt with file paths (one per line)
```

## Adding Custom Documentation

To include additional files in the benchmark:

1. Create `files_to_index.txt` in project root
2. Add file paths (one per line, can be absolute or relative)
3. Run: `php bin/console app:benchmark --reindex`

## Project Structure

```
src/
├── Command/
│   ├── BenchmarkCommand.php    # Benchmark orchestration
│   └── IngestDocsCommand.php   # Document indexing
└── Controller/
    └── ChatController.php      # Chat interface + SSE streaming
```

---

## Results

Benchmark results are saved in `benchmark_results.csv` with:
- Timestamp, Source, Category, Question
- ChatBot Response, Score (0-5), Judge Reason
- Model Used, Response Time (ms)

## References

- [Ollama API](https://github.com/ollama/ollama/blob/main/docs/api.md)
- [pgvector](https://github.com/pgvector/pgvector)
- [API Platform Docs](https://api-platform.com/docs/)
