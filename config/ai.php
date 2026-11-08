<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Knowledge Base Retrieval
    |--------------------------------------------------------------------------
    |
    | `min_score` is the minimum cosine similarity a chunk must reach before it
    | is added to the chatbot's context. Without a floor the retriever always
    | returns top-k chunks, even for questions the knowledge base does not
    | answer, which nudges the model to invent an answer.
    |
    | The right value depends on the embedding provider, so it is configurable.
    | Set it to 0 to restore the old "always return top-k" behaviour.
    |
    */

    'retrieval' => [
        'min_score' => (float) env('AI_RETRIEVAL_MIN_SCORE', 0.25),

        /*
        | Query embeddings are cached per workspace and embedding model so a
        | repeated customer question ("where is my order?") does not call the
        | provider again. Set to 0 to disable caching.
        */
        'embedding_cache_ttl' => (int) env('AI_EMBEDDING_CACHE_TTL', 3600),

        /*
        | Hybrid search: how much an exact keyword match lifts a chunk's cosine
        | score (0..1). The boost only raises scores, so the relevance floor
        | above keeps its meaning. Set to 0 for pure semantic search.
        */
        'keyword_boost' => (float) env('AI_RETRIEVAL_KEYWORD_BOOST', 0.3),

        /*
        | Hard cap on the knowledge-base context injected into one prompt.
        | Retrieved chunks are added in rank order until this budget is spent;
        | without it a handful of large chunks can crowd out the conversation
        | history (or exceed the model's context window).
        */
        'max_context_tokens' => (int) env('AI_MAX_CONTEXT_TOKENS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming
    |--------------------------------------------------------------------------
    |
    | When enabled, the chatbot playground can stream replies token by token
    | over server-sent events. Providers without native streaming still work:
    | their full reply is sent as a single event, so the UI never breaks.
    |
    */

    'streaming' => [
        'enabled' => (bool) env('AI_STREAMING_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Pricing
    |--------------------------------------------------------------------------
    |
    | Cents per one million tokens, used to fill `ai_runs.cost_cents` and the
    | cost figures in reports. A model missing from this map records 0 rather
    | than an invented number, so add an entry before relying on the reports
    | for a new model. Prices change often — keep these current.
    |
    | Embedding calls are recorded at 0: the embedding model is not carried on
    | the run row, so it cannot be priced here yet.
    |
    */

    'pricing' => [
        'gpt-4o-mini' => ['input' => 15.0, 'output' => 60.0],
        'gpt-4o' => ['input' => 250.0, 'output' => 1000.0],
        'claude-3-haiku-20240307' => ['input' => 25.0, 'output' => 125.0],
        'gemini-1.5-flash' => ['input' => 7.5, 'output' => 30.0],
    ],

];
