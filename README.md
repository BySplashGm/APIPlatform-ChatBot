# Prototype ChatBot API Platform

Réalisation d'un prototype de ChatBot RAG (Retrieval-Augmented Generation) pour la documentation d'API Platform, propulsé par **Symfony** et **Ollama**.

## Sommaire
- [Architecture Technique](#architecture-technique)
- [Le Pipeline RAG](#le-pipeline-rag)
- [Choix Techniques](#choix-techniques)
- [Prérequis](#prérequis)
- [Installation et Utilisation](#installation-et-utilisation)
- [Fonctionnement détaillé](#fonctionnement-détaillé)
- [Sources et références](#sources-et-références)

## Architecture Technique

Ce prototype n'utilise aucune API cloud payante. Tout tourne en local via Docker.

* **Framework :** Symfony 7.4 (PHP 8.2+)
* **LLM (Chat) :** Mistral 7B (via Ollama)
* **Embeddings :** Nomic Embed Text (via Ollama)
* **Base de données :** PostgreSQL 16 + extension `pgvector`
* **Frontend :** Twig, TailwindCSS, Vanilla JS (Streaming SSE)

## Le Pipeline RAG

L'objectif est de permettre à une IA de répondre à des questions techniques en se basant uniquement sur la documentation officielle.

1.  **Ingestion :** Une commande Symfony scanne les fichiers Markdown, les découpe en morceaux (chunks) et calcule leurs vecteurs mathématiques.
2.  **Stockage :** Les vecteurs et le texte sont stockés dans PostgreSQL.
3.  **Recherche :** Lors d'une question, on compare le vecteur de la question avec ceux de la base (recherche de similarité cosinus).
4.  **Génération :** On envoie le contexte trouvé à Mistral avec des instructions strictes pour générer la réponse.

## Choix Techniques

| Composant | Choix | Pourquoi ? |
| :--- | :--- | :--- |
| **Backend** | **Symfony** | Intégration robuste via `HttpClient`. Contrôle total sur le flux de données et la gestion des timeouts. |
| **Moteur IA** | **Ollama** | Permet de faire tourner des modèles Open Source (Mistral, Llama 3) en local, gratuitement et sans fuite de données. |
| **Vector Store** | **PostgreSQL (pgvector)** | Évite d'avoir une base de données vectorielle séparée (comme Chroma ou Qdrant). Permet de garder les données relationnelles et vectorielles au même endroit. |
| **Interface** | **Twig + JS** | Solution légère et intégrée. Utilisation de *Server-Sent Events* (SSE) pour afficher la réponse mot par mot (streaming). |

## Prérequis

* **Docker** et Docker Compose.
* **Ressources matérielles :** Minimum 16Go de RAM recommandés pour Mistral 7B. (Nécessaire pour faire tourner Mistral 7B)
    * *Note : Sur Mac/Windows, pensez à allouer au moins 8Go de RAM à Docker Desktop.*

## Installation et Utilisation

### 1. Démarrer la stack technique
Lancez les conteneurs (PHP, Database, Ollama) :
```bash
docker compose up -d --build
```

### 2. Initialiser les modèles IA (Première fois uniquement)
Il faut télécharger les "cerveaux" directement dans le conteneur Ollama pour qu'ils soient accessibles à l'application.
* `nomic-embed-text` : Pour comprendre le sens du texte (Embeddings).
* `mistral` : Pour rédiger les réponses (Chat).

```bash
# Modèle d'embedding (léger)
docker exec -it chatbot-ollama-1 ollama pull nomic-embed-text

# Modèle de chat (performant, 7B paramètres)
docker exec -it chatbot-ollama-1 ollama pull mistral
```

### 3. Préparer les données pour le benchmark

Le benchmark compare 3 sources de données différentes :
- **docs** : Documentation Markdown uniquement
- **code** : Tests fonctionnels PHP uniquement  
- **combined** : Documentation + Code combinés

```bash
# Cloner les sources nécessaires
git clone https://github.com/api-platform/docs.git docs
git clone https://github.com/api-platform/core.git core

# Créer les migrations si nécessaire
php bin/console doctrine:migrations:migrate

# Option 1: Laisser le benchmark tout indexer automatiquement
php bin/console app:benchmark

# Option 2: Indexer manuellement puis lancer les tests
php bin/console app:ingest docs/ --target=docs --clear --stats
php bin/console app:ingest core/tests/Functional/ --target=code --clear --stats
php bin/console app:ingest docs/ --target=combined --clear --stats
php bin/console app:ingest core/tests/Functional/ --target=combined --stats

# Lancer le benchmark sans ré-indexer
php bin/console app:benchmark --skip-ingest
```

**Configuration des sources**

Pour ajouter plus de chemins à indexer, modifiez le fichier `src/Command/BenchmarkCommand.php` dans la méthode `prepareVectorStores()` :

```php
$sources = [
    'docs' => [
        'paths' => [
            'docs/',
            // Ajoutez d'autres dossiers de documentation
        ],
        'description' => 'Documentation (Markdown)'
    ],
    'code' => [
        'paths' => [
            'core/tests/Functional/',
            'core/src/',  // Exemple: ajouter le code source
            // Ajoutez d'autres dossiers de code
        ],
        'description' => 'Code (PHP tests)'
    ],
    'combined' => [
        'paths' => [
            'docs/',
            'core/tests/Functional/',
            'core/src/',
            // Listez tous les chemins à combiner
        ],
        'description' => 'Combined (Docs + Code)'
    ]
];
```

### 4. Lancer le benchmark

```bash
# Benchmark complet (indexation + tests sur 3 sources)
php bin/console app:benchmark

# Benchmark rapide (utilise les données déjà indexées)
php bin/console app:benchmark --skip-ingest
```

**Résultats :**
- Les résultats sont sauvegardés dans `benchmark_results.csv`
- Chaque ligne contient : Date, Source (docs/code/combined), Catégorie, Question, Réponse, Score (0-5), Raison du juge, Modèle, Temps de réponse (ms)
- Le benchmark teste 10 questions × 3 sources = 30 tests au total

### 5. Utiliser le ChatBot
Une fois l'indexation terminée, ouvrez votre navigateur et accédez à l'interface de chat :

```
http://127.0.0.1:8000
```
*(Ou l'URL configurée sur votre environnement local).*

---

## Fonctionnement détaillé

### Commande d'ingestion (`src/Command/IngestDocsCommand.php`)
Le script implémente une stratégie de **Chunking (découpage)** par paragraphes. Plutôt que de découper de manière arbitraire au milieu d'une phrase, il utilise les doubles sauts de ligne pour préserver la cohérence sémantique. Chaque "chunk" est ensuite envoyé à l'API locale d'Ollama pour être transformé en un vecteur de **768 dimensions** (spécificité du modèle `nomic-embed-text`).



### Le Contrôleur de Chat (`src/Controller/ChatController.php`)
Le contrôleur orchestre le flux RAG (Retrieval-Augmented Generation) en temps réel :

1. **Vectorisation de la requête** : La question utilisateur est convertie en vecteur via Ollama.
2. **Recherche de similarité** : PostgreSQL effectue une recherche via l'opérateur `<=>` (distance cosinus) pour extraire les 3 morceaux de documentation les plus pertinents par rapport à la question.
3. **Augmentation du Prompt** : Les morceaux de texte trouvés sont injectés dans un "System Prompt" restrictif qui force l'IA à rester factuelle et à ne pas inventer d'informations.
4. **Streaming Robuste** : Utilisation d'un buffer PHP pour décoder les fragments JSON envoyés par Ollama et les transmettre instantanément au format SSE (Server-Sent Events) vers le frontend.



---

## Sources et références

* [Symfony HttpClient](https://symfony.com/doc/current/http_client.html)
* [Ollama API Reference](https://github.com/ollama/ollama/blob/main/docs/api.md)
* [pgvector : Vector similarity search for Postgres](https://github.com/pgvector/pgvector)
* [Documentation API Platform](https://api-platform.com/docs/)