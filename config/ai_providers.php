<?php

/*
| AI provider presets shown in the admin and the installer. Picking one fills
| driver, Base URL and model suggestions; the model field still accepts any
| name, so new models work without a code change.
|
| driver: openai (OpenAI-compatible /chat/completions), anthropic (/v1/messages),
|         azure (Azure OpenAI deployment URL + api-key header)
| embedding: suggested embedding model when the provider offers /embeddings
| key_required: false for local servers (Ollama)
*/

return [
    // ---- International ----
    'openai' => [
        'label' => 'OpenAI', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://api.openai.com/v1',
        'models' => ['gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o', 'gpt-4o-mini'],
        'embedding' => 'text-embedding-3-small', 'vision' => true,
    ],
    'anthropic' => [
        'label' => 'Anthropic Claude', 'group' => '国际', 'driver' => 'anthropic',
        'base_url' => 'https://api.anthropic.com',
        'models' => ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'],
        'embedding' => null, 'vision' => true,
    ],
    'gemini' => [
        'label' => 'Google Gemini', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'models' => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'],
        'embedding' => 'gemini-embedding-001', 'vision' => true,
    ],
    'azure' => [
        'label' => 'Azure OpenAI', 'group' => '国际', 'driver' => 'azure',
        'base_url' => 'https://{资源名}.openai.azure.com/openai/deployments/{部署名}',
        'models' => ['gpt-4.1', 'gpt-4o', 'gpt-4o-mini'],
        'embedding' => null, 'vision' => true,
        'hint' => 'Base URL 填到部署名为止；模型填部署对应的模型名（仅用于记录）',
    ],
    'xai' => [
        'label' => 'xAI Grok', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://api.x.ai/v1',
        'models' => ['grok-4', 'grok-3', 'grok-3-mini'],
        'embedding' => null, 'vision' => true,
    ],
    'mistral' => [
        'label' => 'Mistral', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://api.mistral.ai/v1',
        'models' => ['mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest'],
        'embedding' => 'mistral-embed', 'vision' => true,
    ],
    'cohere' => [
        'label' => 'Cohere', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://api.cohere.ai/compatibility/v1',
        'models' => ['command-a-03-2025', 'command-r-plus'],
        'embedding' => 'embed-v4.0', 'vision' => false,
    ],
    'perplexity' => [
        'label' => 'Perplexity', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://api.perplexity.ai',
        'models' => ['sonar-pro', 'sonar'],
        'embedding' => null, 'vision' => false,
    ],
    'groq' => [
        'label' => 'Groq', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://api.groq.com/openai/v1',
        'models' => ['llama-3.3-70b-versatile', 'openai/gpt-oss-120b'],
        'embedding' => null, 'vision' => false,
    ],
    'together' => [
        'label' => 'Together AI', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://api.together.xyz/v1',
        'models' => ['meta-llama/Llama-3.3-70B-Instruct-Turbo', 'deepseek-ai/DeepSeek-V3', 'Qwen/Qwen2.5-72B-Instruct-Turbo'],
        'embedding' => 'BAAI/bge-large-en-v1.5', 'vision' => false,
    ],
    'openrouter' => [
        'label' => 'OpenRouter（聚合）', 'group' => '国际', 'driver' => 'openai',
        'base_url' => 'https://openrouter.ai/api/v1',
        'models' => ['openai/gpt-4.1', 'anthropic/claude-sonnet-5-5', 'google/gemini-2.5-pro', 'deepseek/deepseek-chat'],
        'embedding' => null, 'vision' => true,
    ],

    // ---- China ----
    'deepseek' => [
        'label' => 'DeepSeek 深度求索', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://api.deepseek.com/v1',
        'models' => ['deepseek-chat', 'deepseek-reasoner'],
        'embedding' => null, 'vision' => false,
    ],
    'qwen' => [
        'label' => '通义千问（阿里云百炼）', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
        'models' => ['qwen-max', 'qwen-plus', 'qwen-turbo', 'qwen-vl-max'],
        'embedding' => 'text-embedding-v3', 'vision' => true,
        'hint' => '海外账号用 https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
    ],
    'doubao' => [
        'label' => '豆包（火山方舟）', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'models' => ['doubao-seed-1-6-250615', 'doubao-1-5-pro-32k-250115'],
        'embedding' => null, 'vision' => true,
        'hint' => '模型可填模型 ID 或推理接入点 ID（ep-xxxx）',
    ],
    'moonshot' => [
        'label' => 'Kimi（月之暗面）', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://api.moonshot.cn/v1',
        'models' => ['kimi-k2-0905-preview', 'kimi-latest', 'moonshot-v1-32k'],
        'embedding' => null, 'vision' => false,
    ],
    'zhipu' => [
        'label' => '智谱 GLM', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'models' => ['glm-4.5', 'glm-4-plus', 'glm-4-flash', 'glm-4v-plus'],
        'embedding' => 'embedding-3', 'vision' => true,
    ],
    'qianfan' => [
        'label' => '文心一言（百度千帆）', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://qianfan.baidubce.com/v2',
        'models' => ['ernie-4.5-turbo-128k', 'ernie-4.0-turbo-8k', 'ernie-speed-128k'],
        'embedding' => 'embedding-v1', 'vision' => false,
    ],
    'hunyuan' => [
        'label' => '腾讯混元', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://api.hunyuan.cloud.tencent.com/v1',
        'models' => ['hunyuan-turbos-latest', 'hunyuan-large', 'hunyuan-vision'],
        'embedding' => 'hunyuan-embedding', 'vision' => true,
    ],
    'minimax' => [
        'label' => 'MiniMax', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://api.minimaxi.com/v1',
        'models' => ['MiniMax-M1', 'MiniMax-Text-01'],
        'embedding' => null, 'vision' => false,
    ],
    'spark' => [
        'label' => '讯飞星火', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://spark-api-open.xf-yun.com/v1',
        'models' => ['4.0Ultra', 'generalv3.5', 'lite'],
        'embedding' => null, 'vision' => false,
        'hint' => 'API Key 填控制台的 APIPassword',
    ],
    'stepfun' => [
        'label' => '阶跃星辰', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://api.stepfun.com/v1',
        'models' => ['step-2-16k', 'step-1-8k', 'step-1v-8k'],
        'embedding' => null, 'vision' => true,
    ],
    'yi' => [
        'label' => '零一万物', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://api.lingyiwanwu.com/v1',
        'models' => ['yi-lightning', 'yi-vision-v2'],
        'embedding' => null, 'vision' => true,
    ],
    'siliconflow' => [
        'label' => '硅基流动（聚合）', 'group' => '国内', 'driver' => 'openai',
        'base_url' => 'https://api.siliconflow.cn/v1',
        'models' => ['deepseek-ai/DeepSeek-V3', 'Qwen/Qwen2.5-72B-Instruct', 'THUDM/glm-4-9b-chat'],
        'embedding' => 'BAAI/bge-m3', 'vision' => false,
    ],

    // ---- Self-hosted / other ----
    'ollama' => [
        'label' => 'Ollama（本地部署）', 'group' => '其他', 'driver' => 'openai',
        'base_url' => 'http://127.0.0.1:11434/v1',
        'models' => ['qwen2.5:14b', 'llama3.1:8b'],
        'embedding' => 'nomic-embed-text', 'vision' => false, 'key_required' => false,
    ],
    'custom' => [
        'label' => '其他 OpenAI 兼容接口', 'group' => '其他', 'driver' => 'openai',
        'base_url' => '', 'models' => [], 'embedding' => null, 'vision' => false,
        'hint' => 'vLLM、LM Studio、One API / New API 等中转都可以用',
    ],
];
