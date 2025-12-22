#!/bin/bash

DOCS_DIR="api-platform-docs"

if [ ! -d "$DOCS_DIR/.git" ]; then
    echo "=========================="
    echo "FIRST RUN: Cloning docs..."
    echo "=========================="
    git clone https://github.com/api-platform/docs.git $DOCS_DIR
    echo "Indexing files..."
    vectorcode vectorise $DOCS_DIR/**/*.md
else
    echo "===================================="
    echo "Docs already downloaded. Updating..."
    echo "===================================="
    cd $DOCS_DIR && git pull && cd ..
    echo "Reindexing updated files..."
    vectorcode vectorise $DOCS_DIR/**/*.md
fi

echo "================================="
echo "Waiting for Ollama to be ready..."
echo "================================="

until curl -s http://ollama:11434/api/tags > /dev/null; do
  echo "Ollama is still starting..."
  sleep 2
done

echo "Pulling model qwen2.5-coder:1.5b..."
curl -X POST http://ollama:11434/api/pull -d "{\"name\": \"qwen2.5-coder:1.5b\"}"

echo "==================================="
echo "Starting Streamlit App on port 2424"
echo "==================================="

streamlit run app.py \
    --server.port 2424 \
    --server.address 0.0.0.0 \
    --server.headless true \
    --server.fileWatcherType auto