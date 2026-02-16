# API Platform RAG ChatBot (High-Performance Edition)

A high-performance Retrieval-Augmented Generation (RAG) chatbot for API Platform documentation.
It uses a **Hybrid RAG** approach, combining Markdown documentation and real PHP code (fixtures/tests) to provide accurate technical answers.

## 🚀 Key Features

- **Hybrid Retrieval**: Queries both Documentation (`docs`) and Code (`code`) simultaneously for better context.
- **Smart Ingestion**: Auto-detects file types (`.md` vs `.php`), preserves Markdown hierarchy, and uses SQL transactions for instant indexing.
- **AI Judge System**: An impartial LLM evaluates the quality of answers (0-5) based on ground truth.
- **Optimized Performance**:
  - **< 50ms** retrieval time (pgvector).
  - **< 2s** generation time (with quantized models like `phi3:mini`).
  - Strict context window management.

## 🛠️ Tech Stack

- **Backend**: Symfony 7.4 (PHP 8.2+)
- **Database**: PostgreSQL 16 + `pgvector` extension
- **LLM Engine**: Ollama (Local)
  - **Embeddings**: `nomic-embed-text` (Fixed)
  - **Chat Model**: Configurable (`mistral`, `phi3:mini`, `qwen2.5-coder`)
  - **Judge Model**: Configurable (`mistral`, `gpt-4`, etc.)

---

## ⚡ Quick Start

### 1. Start the stack

```bash
docker compose up -d --build
```

### 2. Download AI models

```bash
# 1. The Embedding Model (Mandatory for retrieval)
docker exec -it chatbot-ollama-1 ollama pull nomic-embed-text

# 2. The Chat Model (Choose one)
# Recommended for speed & code:
docker exec -it chatbot-ollama-1 ollama pull phi3:mini 
# Or standard:
docker exec -it chatbot-ollama-1 ollama pull mistral
```

### 3. Clone documentation sources

```bash
git clone [https://github.com/api-platform/docs.git](https://github.com/api-platform/docs.git) docs
git clone [https://github.com/api-platform/core.git](https://github.com/api-platform/core.git) core
```

### 4. Configuration

Create a `.env.local` file to configure your models:

```
# The model used to answer user questions (Fast)
RAG_MODEL_NAME=phi3:mini

# The model used to grade the answers (Smart)
JUDGE_MODEL_NAME=mistral
```

### 5. Ingestion (The "Smart" Way)

First, generate the list of hidden fixture files used in API Platform tests (crucial for "Code as Documentation"):

```bash
php resolve-fixtures.php > files_to_index.txt
```

Then, run the ingestion command. It will automatically route files to `vector_store_docs` or `vector_store_code`:

```bash
# Clears DB and indexes everything in the current directory (docs/ + core/ + files_to_index.txt)
php bin/console app:ingest --clear .
```

### 6. Run the Benchmark

Evaluate the quality and speed of your RAG:

```bash
# Run on 3 random questions with robustness tests
php bin/console app:benchmark --sample 3 --robustness --security
```

## 🏗️ Architecture



### Data Pipeline

1.  **Discovery**: `resolve-fixtures.php` scans `core/tests` to find relevant PHP classes and fixtures used in API Platform's functional tests.
2.  **Ingestion**: `app:ingest` reads files and applies smart chunking:
    - **Markdown**: Preserves header hierarchy (e.g., "Security > Firewall").
    - **PHP**: Splits code by logical blocks with overlap.
3.  **Embedding**: Text chunks are converted to vectors using `nomic-embed-text` via Ollama.
4.  **Storage**: Vectors are committed to PostgreSQL (`pgvector`) in a single transaction for maximum speed. Data is split into two tables: `vector_store_docs` and `vector_store_code`.

### RAG Inference (`RagService`)

1.  **User Query** is converted to a vector using `nomic-embed-text`.
2.  **Hybrid Search**: The system retrieves the Top-2 relevant chunks from Documentation AND the Top-2 relevant chunks from Code.
3.  **Context Assembly**: Chunks are merged into a strict system prompt.
4.  **Generation**: The LLM defined in `RAG_MODEL_NAME` (e.g., `phi3:mini`) generates a concise technical answer based *only* on the retrieved context.

### Evaluation (`JudgeService`)

The chatbot's answer is sent to a separate "Judge" LLM (defined in `JUDGE_MODEL_NAME`) along with the expected ground truth. The judge assigns a score (0-5) and provides a reason for the score.

---

## 📚 Commands Reference

### Ingestion

```bash
# Generate list of hidden fixtures
php resolve-fixtures.php > files_to_index.txt

# Ingest everything in the current directory (docs, core, fixtures)
php bin/console app:ingest --clear .
```

### Benchmark

```bash
# Run a quick test on 3 random questions
php bin/console app:benchmark --sample 3 --light

# Run full benchmark with robustness and security tests
php bin/console app:benchmark --robustness --security

# Re-index everything before running tests
php bin/console app:benchmark --reindex
```

### Options

- `--reindex`: Forces a complete re-indexing of vector stores before running the benchmark. Useful if you modified the documentation or the code.
- `--sample <int>`: Runs the test on a random sample of N questions instead of the full suite.
- `--light`: Activates "Light Mode" (fewer variations per test suite) for faster execution.
- `--robustness`: Enables robustness tests (typos, case sensitivity, punctuation).
- `--security`: Enables security tests (prompt injection, jailbreak attempts).
- `--bias`: Enables bias tests (gender, cultural variations).
- `--context-noise`: Enables context noise tests (adding distracting text to the prompt).
- `--skip-coherence`: Skips the computation of coherence scores (Cosine Similarity), which is CPU-intensive.

## 📊 Results

Benchmark results are automatically saved in `benchmark_results.csv` at the project root.
The CSV contains the following columns:

- **Date**: Timestamp of the test.
- **Source**: `docs` (Markdown), `code` (PHP), or `combined` (Hybrid).
- **Category**: `basic`, `advanced`, `security`, `trap`.
- **Question**: The user query.
- **ChatBot Response**: The actual output from the RAG.
- **Score (0-5)**: Quality score assigned by the AI Judge.
- **Judge Reason**: Explanation for the score.
- **Model Used**: The name of the LLM used for generation (e.g., `phi3:mini`).
- **Response Time (ms)**: Total execution time.

---

## References

- [Ollama API Documentation](https://github.com/ollama/ollama/blob/main/docs/api.md)
- [pgvector Extension](https://github.com/pgvector/pgvector)
- [Symfony 7 Documentation](https://symfony.com/doc/current/index.html)
- [API Platform Documentation](https://api-platform.com/docs/)