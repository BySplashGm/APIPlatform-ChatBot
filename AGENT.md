# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Symfony 7.4 RAG (Retrieval-Augmented Generation) chatbot for API Platform documentation. Uses a hybrid approach: queries both Markdown docs and PHP code (fixtures/tests) to answer technical questions.

## Common Commands

```bash
# Start infrastructure (PostgreSQL + Ollama)
docker compose up -d --build

# Pull required AI models
docker exec -it chatbot-ollama-1 ollama pull nomic-embed-text   # mandatory
docker exec -it chatbot-ollama-1 ollama pull phi3:mini           # or other chat model

# Generate fixture file list (must be done before ingestion)
php resolve-fixtures.php > files_to_index.txt

# Ingest all documents (clears DB and re-indexes everything)
php bin/console app:ingest --clear .

# Ingest a specific path only
php bin/console app:ingest docs/

# Run benchmark (quick sanity check)
php bin/console app:benchmark --sample 3 --light

# Full benchmark with robustness + security tests
php bin/console app:benchmark --robustness --security --skip-coherence

# Run PHPUnit tests
php bin/phpunit
```

## Architecture

### Data Flow

**Ingestion**: `resolve-fixtures.php` → `files_to_index.txt` → `app:ingest` → PostgreSQL (`vector_store_docs` + `vector_store_code`)

**Inference**: User query → `QueryRefiner` (keyword extraction) → Ollama embedding (`nomic-embed-text`) → pgvector similarity search → `RagService` context assembly → Ollama LLM generation → response with source links

**Evaluation**: `app:benchmark` → `JudgeService` (LLM scoring 0-5) + `CoherenceAnalyzer` (cosine similarity) → CSV output

### Key Services

| Service | Responsibility |
|---|---|
| `RagService` | Main orchestrator: embedding, retrieval, LLM call, prompt construction |
| `QueryRefiner` | Pre-processes queries with LLM to extract technical keywords (15s timeout) |
| `VectorStoreManager` | Manages indexing pipeline, spawns `app:ingest` subprocess per path |
| `JudgeService` | Evaluates answer quality (0-5) using a separate judge LLM |
| `CoherenceAnalyzer` | Cosine similarity across responses for consistency checking |

### Vector Storage

Two separate PostgreSQL tables (pgvector, 384-dim `nomic-embed-text` vectors):
- `vector_store_docs` — Markdown docs (header-aware chunking, preserves hierarchy, 1500 char max)
- `vector_store_code` — PHP code (1500 char chunks, 200 char overlap)

`combined` source mode retrieves top-2 from each table (4 chunks total).

### Chunking Strategy

- **Markdown**: Splits on headers, preserves hierarchy in each chunk (e.g., "Security > Firewall > Config"), max 1500 chars
- **PHP**: Fixed-size with 200-char overlap, wrapped in code fences

### LLM Call Details (RagService)

- Direct HTTP to Ollama `/api/chat` (not via symfony/ai-bundle)
- Temperature: 0.0 (deterministic)
- Context window: 4096 tokens, max output: 512 tokens
- Generation timeout: 120s
- Context truncated to 16KB max, user input sanitized

## Environment Variables

```env
DATABASE_URL=postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16
OLLAMA_URL=http://localhost:11434
RAG_MODEL_NAME=qwen2.5-coder:14b    # Chat/answer generation model
REFINER_MODEL_NAME=qwen2.5-coder:14b # Query refinement model
JUDGE_MODEL_NAME=llama3.1:8b        # Answer evaluation model
CHAT_SOURCE=combined                 # docs | code | combined
```

Configure via `.env.local` (never commit secrets).

## Benchmark Test Generators

Located in `src/Service/Benchmark/TestGenerator/`. Each generates variations of base questions:
- `RobustnessTestGenerator`: typos, case, punctuation
- `SecurityTestGenerator`: prompt injection, jailbreak, SQL/command injection
- `BiasTestGenerator`: gender/cultural variations
- `ContextNoiseTestGenerator`: distracting context injection

All support `--light` mode (fewer variations). Results saved to `benchmark_results_detailed.csv` and `benchmark_results_stats.csv`.

## External Source Repositories

The `docs/` and `core/` directories are external git clones (API Platform docs and core library). These are the source material for ingestion — not part of this project's code.
