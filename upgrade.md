# Intégrer le ChatBot directement sur la doc

## Conserver VectorCode en moteur RAG

VectorCode est spécialisé dans l'indexaction de code ce qui le rend efficace et pertinent dans cet usage. En revanche cela pose une contrainte car c'est un outil python or la doc est en next.js d'ou le besoin de découper en services.

## Services

- Next.js: interface utilisateur (existe déjà) pour remplacer Chainlit utilisé dans mon prototype
- API dédiée: exécute vectorcode et renvoie le résultat à un IA qui génère une réponse à l'utilisateur (donc API REST simple)

> Note: La persistance de la documentation indexée est possible grâce à un volume docker ou une bdd vectorielle (ChromaDB ou Qdrant par exemple)

## Fonctionnement théorique

1. Envoi d'un message au ChatBot : requête à l'API
2. Recherche dans les données indexées grâce à VectorCode en utilisant le prompt de l'utilisateur.
3. Appel d'une IA pour construire une réponse à partir du prompt de l'utilisateur et des données qui ont été query.
4. Renvoyer la réponse à l'utilisateur -> affichage

## Réaliser l'API

Différents choix d'API :
- FastAPI
- Flask
- Django REST Framework (DRF)

FastAPI semble le plus adapté car très rapide (VectorCode nécessite du temps pour retrieve les données, la génération du message aussi). FastAPI permet aussi de valider la structure des données (ajoute de la sécurité j'imagine). Il est facile à déployer, optimisé pour les appels concurrents et très léger donc idéal pour un service.

Flask est léger mais moins adapté aux longs temps d'attente (ce qui ne colle pas avec le temps de retrieve et la génération du message). Pas optimisé pour les opérations asynchrones donc risque de ralentissement ?

DRF est conçu pour les applications avec énorméments de données (grosse application web). Il est très puissant mais trop lourd pour un chatbot selon moi car dépendances inutiles et complexifie la conteneurisation

> FastAPI semble l'option la plus viable pour ce projet.

## Ce qu'il manque

Évaluer la qualité des réponses, trouver le bon modèle et bien le pré-prompter (lui préciser son rôle et les règles qu'il doit suivre)

# Liens

[VectorCode](https://github.com/Davidyz/VectorCode)
[FastAPI](https://fastapi.tiangolo.com/)
[Flask](https://flask.palletsprojects.com/)
[DRF](https://www.django-rest-framework.org/)

## A etudier davantage

[Routes API Nextjs](https://nextjs.org/docs/pages/building-your-application/routing/api-routes)
[Assurance de Qualité - Evaluation des IA](https://afup.org/talks/5235-l-evaluation-des-ias-la-recette-secrete-des-agents-pas-trop-betes)
[Assurance de Qualité - Métrique RAG](https://docs.ragas.io/en/latest/)
