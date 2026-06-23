---
title: RAG
tags: [ai, rag, llm]
---

# RAG — Retrieval-Augmented Generation

RAG augments a language model with an external knowledge store so answers are
grounded in your own documents.

## Pipeline

1. **Chunk** documents.
2. **Embed** chunks into vectors.
3. **Retrieve** top-k by similarity.
4. **Generate** an answer conditioned on the retrieved context.

```text
query -> embed -> vector search -> context -> LLM -> answer
```

This [[Marketing Plan]] mentions a RAG starter guide as a lead magnet.

#ai #rag #llm
