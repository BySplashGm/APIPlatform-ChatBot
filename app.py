import os
import subprocess
import json
import asyncio
import ollama
from fastapi import FastAPI, Request
from fastapi.responses import StreamingResponse

app = FastAPI()

MODEL_ID = "qwen2.5-coder:1.5b"
OLLAMA_HOST = os.getenv("OLLAMA_HOST", "http://ollama:11434")

client = ollama.AsyncClient(host=OLLAMA_HOST)

async def search_vectorcode_async(query):
    def run_command():
        try:
            result = subprocess.run(["vectorcode", "query", query], capture_output=True, text=True)
            output = result.stdout.strip()
            clean_lines = [l for l in output.split('\n') if "querying" not in l.lower() and l.strip()]
            return "\n".join(clean_lines)
        except: return "Erreur recherche."
    return await asyncio.to_thread(run_command)

@app.get("/v1/models")
async def list_models():
    return {"data": [{"id": "api-platform-bot"}]}

@app.post("/v1/chat/completions")
async def chat_handler(request: Request):
    data = await request.json()
    user_query = data["messages"][-1]["content"]
    
    context = await search_vectorcode_async(user_query)
    
    prompt = f"""
        <instructions>
        Tu es un expert API Platform. 
            Utilise UNIQUEMENT le contexte documentaire ci-dessous.
            Si la réponse n'est pas dans le contexte, dis-le.
            Réponds avec du code PHP 8.
        </instructions>

        <context>
        {context}
        </context>

        <user_question>
        {user_query}
        </user_question>

        Réponse technique :
    """

    async def stream_gen():
        try:
            async for chunk in await client.chat(
                model=MODEL_ID,
                messages=[
                    {'role': 'system', 'content': 'Tu es un expert API Platform. Tu réponds de manière concise et technique.'},
                    {'role': 'user', 'content': prompt}
                ],
                stream=True,
            ):
                content = chunk['message']['content']
                if content:
                    payload = {"choices": [{"delta": {"content": content}, "finish_reason": None}]}
                    yield f"data: {json.dumps(payload)}\n\n"
            
            yield "data: [DONE]\n\n"
        except Exception as e:
            yield f"data: {json.dumps({'choices': [{'delta': {'content': f'Erreur Ollama: {e}'}}]})}\n\n"
            yield "data: [DONE]\n\n"

    return StreamingResponse(stream_gen(), media_type="text/event-stream")

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=2424)