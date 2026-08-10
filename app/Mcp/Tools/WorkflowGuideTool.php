<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\WorkflowGuide;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('workflow_guide')]
#[Description('Ordered how-to for Snitch MCP workflows. Call first (or when unsure). Optional workflow (alias: topic): overview | brand | competitors | influencers | sync_analyze | billing | explore (default overview). Returns steps with tool names, do_not_skip, prerequisites, and notes (queue, whoami, confirm loops).')]
class WorkflowGuideTool extends Tool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'workflow' => ['nullable', 'string', 'in:'.implode(',', WorkflowGuide::WORKFLOWS)],
            'topic' => ['nullable', 'string', 'in:'.implode(',', WorkflowGuide::WORKFLOWS)],
        ]);

        $workflow = $data['workflow'] ?? $data['topic'] ?? 'overview';

        return Response::json(WorkflowGuide::for($workflow));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow' => $schema->string()
                ->enum(WorkflowGuide::WORKFLOWS)
                ->description('Which workflow guide to return. Defaults to overview. Prefer this over topic.')
                ->nullable(),
            'topic' => $schema->string()
                ->enum(WorkflowGuide::WORKFLOWS)
                ->description('Alias for workflow. Same values; use when calling with topic=brand|competitors|…')
                ->nullable(),
        ];
    }
}
