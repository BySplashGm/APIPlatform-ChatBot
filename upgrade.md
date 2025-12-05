# Prototype ChatBot APIPlatform
Réalisation d'un prototype de ChatBot pour la documentation d'Api Platform.

## Sommaire
- [Transcrire la documentation en vecteurs](#transcrire-la-documentation-en-vecteurs)
- [RAG](#rag)
- [Récupérer la documentation](#récupérer-la-documentation)
- [Interface Utilisateur](#interface-utilisateur)
- [Procédure du prototype](#procédure-du-prototype)
- [Utiliser le prototype](#utiliser-le-prototype)
- [Sources et références](#sources-et-références)

## Transcrire la documentation en vecteurs

L'objectif est d'indexer la documentation pour permettre une recherche sémantique (RAG).

- VectorCode: permet de vectorialiser une documentation et de réaliser des query qui renverront les éléments pertinents dans la documentation.
- Utiliser un LLM d'embedding tel que MiniLM-L6 de sentence-transformers

| Choix | Avantages | Inconvénients |
| ----- | --------- | ------------- |
| VectorCode | Spécialisé dans l'indexation de code (connait les structures). Interface CLI simple. Utilise des LLM performants. | Projet encore en beta donc potentiels changements majeurs à prévoir dans le futur. |
| LLM d'embedding | Plus de documentation et grande communauté. Permet un contrôle total sur le chunking des documents | Pas spécialisé, nécessite davantage d'outils pour faire fonctionner. (Serveur Qdrant par exemple). Plus grande complexité. |

> Choix réalisé pour le prototype: VectorCode (utilisant ChromaBD)

## Récupérer la documentation

La documentation est disponible sur [GitHub](https://github.com/api-platform/docs). Possibilité de `clone` et `pull` le repository.

## Interface Utilisateur

Afin de permettre une utilisation simple pour l'utilisateur, de nombreux choix sont disponibles

| Choix | Avantages | Inconvénients |
| ----- | --------- | ------------- |
| Chainlit | Très rapide pour prototyper. Historique de chat, gestion de réglages utilisateur (sélection de modèle par exemple). | Peu de flexibilité graphique. Dépendences python prédéfinies (Engine.IO/Socker.IO) qui peuvent causer des erreurs |
| OpenUI | Permet à l'IA de générer l'interface elle même. | Encore très récent, documentation limitée. |
| Développer soi-même | Libertée totale. Architecture en micro-service "standard" (API Backend et Frontend) | Nécessite de maintenir deux projets. Plus de temps nécessaire. |

> Choix réalisé pour le prototype: Chainlit

## Procédure du prototype

Un conteneur Docker permet de faire fonctionner le prototype. Ce dernier tourne sous l'image Python 3.11-slim (une image légère à la version requise par VectorCode). Un volume est attribué afin de ne pas devoir re-télécharger la documentation à chaque exécution du conteneur. Des fichiers nécessaires au fonctionnement du prototype sont copiés dans le conteneur (tel que `requirements.txt`).

Le conteneur va tout d'abord installer **git** puis les dépendances nécessaires à faire tourner le programme dans le fichier `app.py`. Une fois cela réalisé il utilisera le script `start.sh`. Ce script vérifie si la documentation est déjà téléchargée. Si la documentation est téléchargée, elle est mise à jour, sinon elle est téléchargée. Ensuite, la documentation est vectorialisée par VectorCode qui enverra les vecteurs dans ChromaDB. Chainlit est ensuite démarré sous le port **2424**.

Le script python `app.py` appelé dans la commande de démarrage de Chainlit cherche dans le fichier `.env` une clé [API Google](https://aistudio.google.com/) (GOOGLE_API_KEY). Lorsqu'un message est envoyé sur l'interface de Chainlit, la commande de query de VectorCode est appelée. VectorCode renverra les documents qui seront envoyés à Gemini (modèle choisi en raison d'un accès gratuit simple pour prototyper). Gemini répondra au message de en utilisant et appliquant les données que VectorCode a renvoyé précédemment au contexte demandé par l'utilisateur.

## Utiliser le prototype

1. Lancez le docker
```sh
docker compose up -d --build
```

2. Ouvrez votre navigateur et rendez-vous sur l'URL
```
http://localhost:2424
```

## Sources et références

- [VectorCode](https://github.com/Davidyz/VectorCode)
- [LLMs Sentence Transformers](https://sbert.net/)
- [Chainlit](https://docs.chainlit.io/)
- [OpenUI](https://github.com/wandb/openui)
- [Streamlit](https://streamlit.io/)
