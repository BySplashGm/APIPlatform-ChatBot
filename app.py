import chainlit as cl
import subprocess
import os
import google.generativeai as genai

genai.configure(api_key=os.environ.get("GOOGLE_API_KEY"))

model = genai.GenerativeModel('gemini-flash-latest')

def search_vectorcode(query):
    try:
        result = subprocess.run(
            ["vectorcode", "query", query], 
            capture_output=True, 
            text=True
        )
        return result.stdout
    except Exception as e:
        return f"Error while searching: {e}"

@cl.on_message
async def chat_handler(message: cl.Message):
    msg = cl.Message(content="")
    await msg.send()
    
    await msg.stream_token("Searching in API Platform's Docs...\n\n")

    context = search_vectorcode(message.content)
    
    full_prompt = f"""
    Rôle : Tu es un expert technique sur API Platform (Symfony).
    
    Tâche : Réponds à la question de l'utilisateur en te basant EXCLUSIVEMENT sur le contexte fourni ci-dessous.
    
    --- CONTEXTE DOCUMENTAIRE ---
    {context}
    --- FIN CONTEXTE ---
    
    Question utilisateur : {message.content}
    """

    response = model.generate_content(full_prompt, stream=True)

    for chunk in response:
        if chunk.text:
            await msg.stream_token(chunk.text)
    
    await msg.update()
