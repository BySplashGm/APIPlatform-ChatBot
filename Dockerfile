FROM python:3.11-slim

RUN apt-get update && apt-get install -y git && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
COPY app.py .
COPY start.sh .
COPY chainlit.md .
COPY logo.png .

RUN mkdir -p .chainlit
COPY .chainlit/ .chainlit/

RUN pip install --no-cache-dir -r requirements.txt

RUN chmod +x start.sh

EXPOSE 2424

CMD ["./start.sh"]