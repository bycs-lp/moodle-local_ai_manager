---
title: Todo
---

# Indexing
* [] Controls for allowing a module / area / item to be processed for RAG indexing
  * [] Extend module edit form to have a selector to explicitly require turning indexing for RAG for that activity.
  * [] Ensure we have a process for determing if the indexing flag is going from "off" to "on" and vice versa.
  * [] Ensure that when indexing flag goes from "on" to "off" there is a process for cleaning up.
* [ ] Automate initialisation of Qdrant collection. 
    Post to <host>/collections/<collection_name>/create with body:
    ```
    {
      "vectors": {
        "contentvector": {
            "size": 1536,
            "distance": "Cosine"
        }
      }
    }
  ```
* [] Basic text indexing of "simple"  activity content (e.g. page, book)
* [] Identify modules where built-in global search indexing code won't create a rag suitable artefact for embedding.
* [] A "Full" index should destroy the qdrant collection and recreate it from scratch.

# Retrieval

# Miscellaneous items