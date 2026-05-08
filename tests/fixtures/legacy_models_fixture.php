<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_manager\local;

// This file is loaded via require_once from tests, not via PSR-4 autoloading.

/**
 * Fixtures class containing the legacy hardcoded get_models_by_purpose data from the main branch
 * (commit b54fb87, before model management was introduced).
 *
 * This is used in unit tests to verify that the new model management system (JSON import + DB)
 * produces results equivalent to the old hardcoded connector implementations.
 *
 * @package   local_ai_manager
 * @copyright 2026 ISB Bayern
 * @author    Philipp Memmel
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class legacy_models_fixture {

    /**
     * Returns the legacy hardcoded models for the chatgpt connector, grouped by purpose.
     *
     * Includes the Azure preconfigured model that was dynamically added.
     *
     * @return array
     */
    public static function chatgpt_get_models_by_purpose(): array {
        // Note: The old code had dead code after a return statement that would have added
        // chatgpt_preconfigured_azure to all purposes. That code never executed, so the
        // azure model was only available via admin configuration, not via get_models_by_purpose().
        $chatgptmodels = [
            'gpt-3.5-turbo', 'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini',
            'o1', 'o1-mini', 'o3', 'o3-mini', 'o4-mini',
            'chatgpt_preconfigured_azure',
        ];
        $ittmodels = [
            'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini', 'o1', 'o3', 'o4-mini',
            'chatgpt_preconfigured_azure',
        ];
        return [
            'chat' => $chatgptmodels,
            'feedback' => $chatgptmodels,
            'singleprompt' => $chatgptmodels,
            'translate' => $chatgptmodels,
            'tts' => [],
            'imggen' => [],
            'itt' => $ittmodels,
            'questiongeneration' => $chatgptmodels,
            'agent' => $chatgptmodels,
        ];
    }

    /**
     * Returns the legacy hardcoded models for the dalle connector, grouped by purpose.
     *
     * @return array
     */
    public static function dalle_get_models_by_purpose(): array {
        $empty = [];
        return [
            'chat' => $empty,
            'feedback' => $empty,
            'singleprompt' => $empty,
            'translate' => $empty,
            'tts' => $empty,
            'imggen' => ['dall-e-3', 'gpt-image-1', 'dalle_preconfigured_azure'],
            'itt' => $empty,
            'questiongeneration' => $empty,
            'agent' => $empty,
        ];
    }

    /**
     * Returns the legacy hardcoded models for the gemini connector, grouped by purpose.
     *
     * @return array
     */
    public static function gemini_get_models_by_purpose(): array {
        $textmodels = ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-2.0-flash', 'gemini-2.0-pro'];
        return [
            'chat' => $textmodels,
            'feedback' => $textmodels,
            'singleprompt' => $textmodels,
            'translate' => $textmodels,
            'tts' => [],
            'imggen' => [],
            'itt' => $textmodels,
            'questiongeneration' => $textmodels,
            'agent' => $textmodels,
        ];
    }

    /**
     * Returns the legacy hardcoded models for the googlesynthesize connector, grouped by purpose.
     *
     * @return array
     */
    public static function googlesynthesize_get_models_by_purpose(): array {
        $empty = [];
        return [
            'chat' => $empty,
            'feedback' => $empty,
            'singleprompt' => $empty,
            'translate' => $empty,
            'tts' => ['googletts'],
            'imggen' => $empty,
            'itt' => $empty,
            'questiongeneration' => $empty,
            'agent' => $empty,
        ];
    }

    /**
     * Returns the legacy hardcoded models for the imagen connector, grouped by purpose.
     *
     * @return array
     */
    public static function imagen_get_models_by_purpose(): array {
        $empty = [];
        return [
            'chat' => $empty,
            'feedback' => $empty,
            'singleprompt' => $empty,
            'translate' => $empty,
            'tts' => $empty,
            'imggen' => [
                'imagen-3.0-generate-002',
                'imagen-4.0-generate-001',
                'imagen-4.0-ultra-generate-001',
                'imagen-4.0-fast-generate-001',
            ],
            'itt' => $empty,
            'questiongeneration' => $empty,
            'agent' => $empty,
        ];
    }

    /**
     * Returns the legacy hardcoded models for the ollama connector, grouped by purpose.
     *
     * @return array
     */
    public static function ollama_get_models_by_purpose(): array {
        $visionmodels = [
            'llava-llama3', 'llava-phi3', 'granite-3.2-vision', 'bakllava', 'moondream',
            'llama3.2-vision', 'llama4', 'gemma3', 'qwen2.5vl', 'mistral-small3.1',
        ];
        $textmodels = [
            'gemma', 'gemma3', 'llama3', 'llama3.1', 'llama3.2-vision', 'llama3.3', 'llama4',
            'phi4', 'mistral', 'mistral-small3.1', 'codellama', 'qwen', 'mixtral',
            'dolphin-mixtral', 'tinyllama',
        ];
        return [
            'chat' => $textmodels,
            'feedback' => $textmodels,
            'singleprompt' => $textmodels,
            'translate' => $textmodels,
            'tts' => [],
            'imggen' => [],
            'itt' => $visionmodels,
            'questiongeneration' => $textmodels,
            'agent' => $textmodels,
        ];
    }

    /**
     * Returns the legacy hardcoded models for the openaitts connector, grouped by purpose.
     *
     * Includes the Azure preconfigured models that were dynamically generated.
     *
     * @return array
     */
    public static function openaitts_get_models_by_purpose(): array {
        $empty = [];
        return [
            'chat' => $empty,
            'feedback' => $empty,
            'singleprompt' => $empty,
            'translate' => $empty,
            'tts' => [
                'tts-1',
                'gpt-4o-mini-tts',
                'openaitts_tts-1_preconfigured_azure',
                'openaitts_gpt-4o-mini-tts_preconfigured_azure',
            ],
            'imggen' => $empty,
            'itt' => $empty,
            'questiongeneration' => $empty,
            'agent' => $empty,
        ];
    }

    /**
     * Returns the legacy hardcoded models for the telli connector, grouped by purpose.
     *
     * Based on the default setting value for aitool_telli/availablemodels.
     *
     * @return array
     */
    public static function telli_get_models_by_purpose(): array {
        $models = [
            'meta-llama/Meta-Llama-3.1-8B-Instruct',
            'gpt-4-turbo',
            'gpt-3.5-turbo',
            'meta-llama/CodeLlama-13b-Instruct-hf',
            'mistralai/Mistral-7B-Instruct-v0.3',
            'mistralai/Mixtral-8x7B-Instruct-v0.1',
            'meta-llama/Meta-Llama-3.1-405B-Instruct-FP8',
            'meta-llama/Llama-3.3-70B-Instruct',
            'mistralai/Mistral-Nemo-Instruct-2407',
            'BAAI/bge-m3',
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-5',
            'gpt-5-mini',
            'gpt-5-nano',
            'gpt-5.5',
        ];
        $visionmodels = ['gpt-4o', 'gpt-4o-mini', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano'];
        $imggenmodels = [
            'dall-e-3',
            'stabilityai/stable-diffusion-xl-base-1.0',
            'black-forest-labs/FLUX.1-schnell',
            'imagen-4.0-generate-001'
        ];
        sort($models);
        sort($visionmodels);
        return [
            'chat' => $models,
            'feedback' => $models,
            'singleprompt' => $models,
            'translate' => $models,
            'tts' => [],
            'itt' => $visionmodels,
            'imggen' => $imggenmodels,
            'questiongeneration' => $models,
            'agent' => $models,
        ];
    }

}


