<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** What an AI call is for; each purpose has its own provider (and backup) under AI 用途分配. */
enum AiPurpose: string implements HasLabel
{
    case Site = 'site';
    case Article = 'article';
    case Image = 'image';
    case Embedding = 'embedding';

    /** Prompt types written for articles; everything else builds the site. */
    protected const ARTICLE_PROMPTS = ['article_generate', 'article_polish', 'article_review', 'fact_check', 'keyword_expand'];

    public function getLabel(): string
    {
        return match ($this) {
            self::Site => '网站生成',
            self::Article => '文章',
            self::Image => '图片生成',
            self::Embedding => '查重向量',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Site => '设计、页面内容、站点数据、品牌补全、界面翻译。要读参考网站截图，需选支持图片输入（视觉）的模型。',
            self::Article => '关键词扩展、写文章、润色。',
            self::Image => '文章插图。需选有图片生成模型的接口（OpenAI 兼容 /images/generations）。',
            self::Embedding => '文章查重用的向量。不配置时只用文字相似度查重。',
        };
    }

    /** The model field of a provider this purpose uses. */
    public function modelField(): string
    {
        return match ($this) {
            self::Image => 'image_model',
            self::Embedding => 'embedding_model',
            default => 'model',
        };
    }

    /** Purpose of a logged call ("article_generate", "image", "site_data"…). */
    public static function forCall(string $purpose): self
    {
        return self::tryFrom($purpose) ?? (in_array($purpose, self::ARTICLE_PROMPTS, true) ? self::Article : self::Site);
    }
}
