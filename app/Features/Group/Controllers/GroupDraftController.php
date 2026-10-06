<?php

namespace App\Features\Group\Controllers;

use App\Features\Group\Exceptions\PublicationUnavailable;
use App\Features\Group\Requests\PublishGroupDraftRequest;
use App\Features\Group\Requests\SaveGroupDraftRequest;
use App\Features\Group\Services\GroupDraftService;
use App\Features\Group\Services\OwnerGroupPage;
use App\Features\Group\Services\OwnerPublicationEligibility;
use App\Features\Group\Services\PublishGroupDraft;
use App\Http\Controllers\Controller;
use App\Models\GroupDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class GroupDraftController extends Controller
{
    public function __construct(
        private readonly GroupDraftService $drafts,
        private readonly OwnerGroupPage $page,
    ) {}

    public function index(Request $request, OwnerPublicationEligibility $eligibility): JsonResponse
    {
        $eligibility->assertCanPrepare($request->user());

        return response()->json(['drafts' => GroupDraft::where('owner_id', $request->user()->id)
            ->where('status', '!=', 'published')->latest('updated_at')->get()->map($this->page->draft(...))]);
    }

    public function edit(Request $request, GroupDraft $draft): \Inertia\Response
    {
        $this->drafts->owned($request->user(), $draft);

        return Inertia::render('Dashboard/Groups/Create', $this->page->props($request->user(), $draft));
    }

    public function show(Request $request, GroupDraft $draft): JsonResponse
    {
        return response()->json(['draft' => $this->page->draft($this->drafts->owned($request->user(), $draft))]);
    }

    public function store(SaveGroupDraftRequest $request): JsonResponse
    {
        $draft = $this->drafts->create($request->user(), $request->validated('id'), $request->validated('data'));

        return response()->json(['draft' => $this->page->draft($draft)], $draft->wasRecentlyCreated ? 201 : 200);
    }

    public function update(SaveGroupDraftRequest $request, GroupDraft $draft): JsonResponse
    {
        $saved = $this->drafts->save($request->user(), $draft, $request->integer('version'), $request->validated('data'));

        return response()->json(['draft' => $this->page->draft($saved)]);
    }

    public function destroy(Request $request, GroupDraft $draft): Response
    {
        $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->drafts->delete($request->user(), $draft, $request->integer('version'));

        return response()->noContent();
    }

    public function publish(PublishGroupDraftRequest $request, GroupDraft $draft, PublishGroupDraft $publish): JsonResponse
    {
        try {
            $group = $publish->publish($request->user(), $draft, $request->integer('version'), $request->safe()->only([
                'credential_email', 'credential_password', 'credential_notes',
            ]));
        } catch (PublicationUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['group_id' => $group->id, 'redirect' => route('dashboard.subscriptions', ['tab' => 'owned'], absolute: false)]);
    }

    public function reopen(Request $request, GroupDraft $draft): JsonResponse
    {
        $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $saved = $this->drafts->reopen($request->user(), $draft, $request->integer('version'));

        return response()->json(['draft' => $this->page->draft($saved)]);
    }
}
