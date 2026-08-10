<?php

namespace App\Mcp\Prompts;

use App\Mcp\Support\WorkflowGuide;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('workflow_guide')]
#[Description('Text version of the Snitch MCP workflow guide. Prefer the workflow_guide tool for structured steps. Optional workflow: overview | brand | competitors | influencers | sync_analyze | billing | explore.')]
class WorkflowGuidePrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $workflow = (string) ($request->get('workflow') ?: 'overview');
        $guide = WorkflowGuide::for($workflow);

        $lines = [
            'Workflow: '.$guide['workflow'],
            $guide['summary'],
            '',
            'Prerequisites:',
            ...array_map(fn (string $line): string => '- '.$line, $guide['prerequisites']),
            '',
            'Do not skip:',
            ...array_map(fn (string $line): string => '- '.$line, $guide['do_not_skip']),
            '',
            'Steps:',
        ];

        foreach ($guide['steps'] as $step) {
            $lines[] = $step['order'].'. '.$step['tool'].' - '.$step['action'];
        }

        if ($guide['notes'] !== []) {
            $lines[] = '';
            $lines[] = 'Notes:';
            foreach ($guide['notes'] as $note) {
                $lines[] = '- '.$note;
            }
        }

        $lines[] = '';
        $lines[] = 'Available workflows: '.implode(', ', $guide['available_workflows']);

        return Response::text(implode("\n", $lines));
    }

    /** @return array<int, Argument> */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'workflow',
                description: 'overview | brand | competitors | influencers | sync_analyze | billing | explore',
                required: false,
            ),
        ];
    }
}
