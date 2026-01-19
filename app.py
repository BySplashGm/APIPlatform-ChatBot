import streamlit as st
import os
import subprocess
import ollama

st.set_page_config(page_title="ChatBot API Platform", page_icon="logo.png", layout="centered")

if "messages" not in st.session_state:
    st.session_state.messages = []

def search_vectorcode(query):
    try:
        result = subprocess.run(["vectorcode", "query", query], capture_output=True, text=True)
        clean_lines = [l for l in result.stdout.strip().split('\n') if "querying" not in l.lower() and l.strip()]
        return "\n".join(clean_lines)
    except Exception:
        return ""

st.title("ChatBot API Platform")
st.caption("Posez vos questions à propos de la documentation.")

for message in st.session_state.messages:
    with st.chat_message(message["role"]):
        st.markdown(message["content"])

if prompt := st.chat_input("Votre question technique..."):
    st.session_state.messages.append({"role": "user", "content": prompt})
    with st.chat_message("user"):
        st.markdown(prompt)

    with st.chat_message("assistant"):
        response_placeholder = st.empty()
        full_response = ""
        
        with st.status("Recherche et analyse...", expanded=True) as status:
            context = search_vectorcode(prompt)
            
            if context:
                with st.expander("Voir le contexte documentaire"):
                    st.code(context, language="markdown")
            
            client = ollama.Client(host=os.getenv("OLLAMA_HOST", "http://ollama:11434"))
            
            full_prompt = f"""
            <instructions>
            Tu es un expert API Platform. 
            Utilise UNIQUEMENT le contexte documentaire ci-dessous.
            Si la réponse n'est pas dans le contexte, dis-le.
            Réponds avec du code PHP 8.
            </instructions>

            <context>
            {context}
            </context>

            <question>
            {prompt}
            </question>
            """

            stream = client.chat(
                model="qwen2.5-coder:1.5b",
                messages=[{'role': 'user', 'content': full_prompt}],
                stream=True,
            )
            
            for chunk in stream:
                content = chunk['message']['content']
                if content:
                    full_response += content
                    response_placeholder.markdown(full_response + "▌")
            
            status.update(label="Réponse terminée", state="complete", expanded=False)
        
        response_placeholder.markdown(full_response)
        st.session_state.messages.append({"role": "assistant", "content": full_response})