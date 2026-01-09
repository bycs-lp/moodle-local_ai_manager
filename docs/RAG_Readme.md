---
title: Local Documentation
---
This covers both the RAG and Text Embedding features.

# Purposes
## Text Embedding Purpose

We have implemented a new "Text Embedding" purpose (`aipurpose_embedding`) which is a specialised purpose specifically to support the generation of vectors from text.

## Retrieval Augmented Generation Purpose

We have implemented a Retrieval Augmented Generation purpose (`aipurpose_rag`) to support the interaction between vector databases to provide grounding information for AI queries.

The `aipurpose_rag` class is also responsible for the indexing and orchestration of relevant aitool plugins to identify, extract, embed and then store Moodle content.

# Tools
A number of new "tools" have been created to support / implement the new purposes:

* aitool_openaite
* aitool_vdb
* aitool_qdrant

## aitool_openaite
`openait` implements a text-embedding tool, that specifically supports Open AI based APIs for the *text embedding* purpose.

## aitool_vdb
`aitool_vdb` is an abstract tool that provides common functionality for tools that will provide RAG purposes.

It is not intended to be instantiated directly.

This class implements a number of functions that **must** be called by implementations to perform security and content checks by Moodle.

This plugin also implements any functionality that may be required for a Moodle activity or "area" to provide a representation of itself that is suitable for RAG storage, where the underlying area does not provide an implementation.

These are implemented in the `vdb/content` directory.

## aitool_qdrant
`aitool_qdrant` implements a concrete RAG / Vector DB tool, extending the `aitool_vdb` plugin, to interact with an instance of a qDrant vector database.

# Demonstrators
## mod_xaichat

`mod_xaichat` is a reference implementation of a chat interface that uses the local_ai_manager, but it is supplemented by the use of the RAG purpose to provide grounding information.

# Installation
## Text Embedding
Text embedding tools are set up similiarly to other other AI tools, but can only be associated with the "text embedding" purpose.

## RAG

Only "concrete" instances of the `vdb` tool can be created.

Each `aitool_vdb` implementation provides the necessary code to interact with a specific vector db store.

### qdrant
Currently RAG is implemented with a qDrant backend, this will require a qdrant server instance to be available.

This just needs to be available within the environment, by default it will expect it to be on port 6333 on localhost.


# Indexing
`php admin/cli/cfig.php --name=task_full_reindex --set=1 --component=aipurpose_rag; php admin/c
li/scheduled_task.php --execute=\\aipurpose_rag\\task\\indexer_task`