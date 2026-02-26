# API Platform RAG ChatBot

A Retrieval-Augmented Generation (RAG) chatbot for API Platform documentation.
It uses a **hybrid RAG** approach — combining Markdown documentation and real PHP code (fixtures/tests) — to provide accurate, grounded technical answers.

## Features

- **Hybrid retrieval**: Queries both `docs` (Markdown) and `code` (PHP) vector stores simultaneously for richer context.
- **Query refinement**: An LLM pre-processes every user query to extract technical keywords before retrieval, improving recall.
- **Smart ingestion**: Auto-detects file types (`.md` vs `.php`), preserves Markdown header hierarchy, and uses SQL transactions for atomic indexing.
- **AI judge system**: A separate LLM evaluates answer quality (0–5) against a ground truth, independent of the generation model.
- **Benchmarking suite**: Pluggable test generators for robustness, security, bias, and context-noise evaluation.

## Tech Stack

- **Backend**: Symfony 7.4 (PHP 8.2+)
- **Database**: PostgreSQL 16 + `pgvector` extension
- **LLM engine**: Ollama (local)
  - **Embeddings**: `nomic-embed-text` (fixed, 384-dim)
  - **Chat / Refiner model**: `qwen2.5-coder:14b` (default, configurable)
  - **Judge model**: `llama3.1:8b` (default, configurable)

---

## Quick Start

### 1. Start the stack

```bash
docker compose up -d --build
```

### 2. Download AI models

```bash
# Embedding model (mandatory)
docker exec -it chatbot-ollama-1 ollama pull nomic-embed-text

# Chat and query-refinement model
docker exec -it chatbot-ollama-1 ollama pull qwen2.5-coder:14b

# Judge model (answer evaluation)
docker exec -it chatbot-ollama-1 ollama pull llama3.1:8b
```

### 3. Clone documentation sources

```bash
git clone https://github.com/api-platform/docs.git docs
git clone https://github.com/api-platform/core.git core
```

### 4. Configuration

Create a `.env.local` file to override model names or other defaults:

```env
# Model used to answer user questions
RAG_MODEL_NAME=qwen2.5-coder:14b

# Model used to rewrite/refine queries before retrieval
REFINER_MODEL_NAME=qwen2.5-coder:14b

# Model used to grade answers during benchmarking
JUDGE_MODEL_NAME=llama3.1:8b

# Retrieval source: docs | code | combined
CHAT_SOURCE=combined
```

### 5. Ingest documents

Generate the list of PHP fixture files used in API Platform tests (crucial for "code as documentation"):

```bash
php resolve-fixtures.php > files_to_index.txt
```

Then run ingestion. It automatically routes each file to `vector_store_docs` or `vector_store_code`:

```bash
# Clear the database and re-index everything (docs/ + core/ + fixtures)
php bin/console app:ingest --clear .
```

### 6. Run the benchmark

```bash
# Quick sanity check on 3 random questions
php bin/console app:benchmark --sample 3 --light

# Full benchmark with robustness and security tests
php bin/console app:benchmark --robustness --security --skip-coherence
```

---

## Architecture

### Data Flow

```
Ingestion:  resolve-fixtures.php → files_to_index.txt → app:ingest → PostgreSQL (vector_store_docs + vector_store_code)

Inference:  User query → QueryRefiner → nomic-embed-text → pgvector search → RagService → Ollama LLM → response + sources

Evaluation: app:benchmark → JudgeService (0–5 score) + CoherenceAnalyzer (cosine similarity) → benchmark_results.csv
```

### Ingestion Pipeline

1. **Discovery**: `resolve-fixtures.php` scans `core/tests` to enumerate PHP fixtures and classes used in API Platform's functional tests.
2. **Chunking**:
   - **Markdown**: Splits on headers, preserves full hierarchy in each chunk (e.g., `Security > Firewall > Config`), max 1 500 chars.
   - **PHP**: Fixed-size chunks with 200-char overlap, wrapped in code fences.
3. **Embedding**: Chunks are converted to 384-dim vectors via `nomic-embed-text`.
4. **Storage**: Vectors are committed to `vector_store_docs` or `vector_store_code` in PostgreSQL (`pgvector`).

### RAG Inference (`RagService`)

1. **Query refinement** (`QueryRefiner`): The user query is rewritten by an LLM to extract precise technical keywords, improving vector search recall.
2. **Embedding**: The refined query is embedded with `nomic-embed-text`.
3. **Hybrid search**: Top-2 chunks from `vector_store_docs` + Top-2 from `vector_store_code` are retrieved (4 chunks total in `combined` mode).
4. **Prompt construction**: Retrieved chunks are assembled into a strict system prompt (16 KB max context).
5. **Generation**: The LLM defined by `RAG_MODEL_NAME` produces a concise, grounded answer with source references.

### Evaluation (`JudgeService`)

Each answer is sent to a separate judge LLM (`JUDGE_MODEL_NAME`) alongside the expected ground truth. The judge assigns a score from 0 to 5 and returns a reasoning explanation.

`CoherenceAnalyzer` measures cosine similarity across multiple responses to the same question to detect inconsistencies.

---

## Commands Reference

### Ingestion

```bash
# Generate the PHP fixture file list
php resolve-fixtures.php > files_to_index.txt

# Ingest and re-index everything
php bin/console app:ingest --clear .

# Ingest a specific path only (incremental)
php bin/console app:ingest docs/
```

### Benchmark

```bash
# Quick sanity check (3 questions, light mode)
php bin/console app:benchmark --sample 3 --light

# Full benchmark with all test suites
php bin/console app:benchmark --robustness --security --bias --context-noise

# Run in parallel (4 concurrent questions)
php bin/console app:benchmark --parallel 4

# Test a specific retrieval source
php bin/console app:benchmark --source docs
php bin/console app:benchmark --source code
php bin/console app:benchmark --source combined
```

### Benchmark Options

| Option | Description |
|---|---|
| `--sample <int>` | Run on N randomly selected questions |
| `--light` | Fewer variations per test generator (faster) |
| `--robustness` | Enable robustness tests (typos, casing, punctuation) |
| `--security` | Enable security tests (prompt injection, jailbreak) |
| `--bias` | Enable bias tests (gender/cultural variations) |
| `--context-noise` | Enable context-noise tests (distracting injections) |
| `--skip-coherence` | Skip cosine-similarity coherence scoring |
| `--parallel <int>` | Number of questions processed concurrently |
| `--source <string>` | Restrict retrieval to `docs`, `code`, or `combined` |
| `--reindex` | Force a full re-index before running tests |

---

## Benchmark Results

Results are saved to `benchmark_results.csv` at the project root with the following columns:

| Column | Description |
|---|---|
| `Date` | Timestamp of the test run |
| `Source` | `docs`, `code`, or `combined` |
| `Category` | `basic`, `advanced`, `security`, `trap` |
| `Question` | The user query |
| `ChatBot Response` | The generated answer |
| `Score (0-5)` | Quality score from the AI judge |
| `Judge Reason` | Explanation of the score |
| `Model Used` | LLM used for generation |
| `Response Time (ms)` | Total execution time |

---

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `DATABASE_URL` | `postgresql://...` | PostgreSQL connection string |
| `OLLAMA_URL` | `http://localhost:11434` | Ollama API endpoint |
| `RAG_MODEL_NAME` | `qwen2.5-coder:14b` | LLM for answer generation |
| `REFINER_MODEL_NAME` | `qwen2.5-coder:14b` | LLM for query refinement |
| `JUDGE_MODEL_NAME` | `llama3.1:8b` | LLM for answer evaluation |
| `CHAT_SOURCE` | `combined` | Retrieval source (`docs` / `code` / `combined`) |

Configure via `.env.local`. Never commit secrets.

---

## References

- [Ollama API Documentation](https://github.com/ollama/ollama/blob/main/docs/api.md)
- [pgvector Extension](https://github.com/pgvector/pgvector)
- [Symfony 7 Documentation](https://symfony.com/doc/current/index.html)
- [API Platform Documentation](https://api-platform.com/docs/)
