#!/bin/bash

DOCS_DIR="api-platform-docs"

if [ ! -d "$DOCS_DIR/.git" ]; then
    echo "=========================="
    echo "FIRST RUN: Cloning docs..."
    echo "=========================="
    git clone https://github.com/api-platform/docs.git $DOCS_DIR
    echo "Indexing files..."
    vectorcode vectorise $DOCS_DIR
else
    echo "===================================="
    echo "Docs already downloaded. Updating..."
    echo "===================================="
    
    cd $DOCS_DIR
    git pull
    cd ..
    
    echo "Reindexing updated files..."
    vectorcode vectorise $DOCS_DIR/**/*.md
fi

echo "======================================"
echo "Starting Chatbot Chainlit on port 2424"
echo "======================================"
chainlit run app.py --port 2424 --host 0.0.0.0