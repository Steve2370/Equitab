import { computed, onBeforeUnmount, reactive, watch } from "vue";
import type { GroupDraft } from "../types/group-draft.ts";
import { createDraftController, createDraftState, draftIsLocked, isDraftSaved } from "../utils/groupDraft.ts";
import { groupDraftApi } from "../utils/groupDraftApi.ts";

export function useGroupDraft(initial: () => GroupDraft | null, confirmed: (draft: GroupDraft) => void | Promise<void>) {
    const state = reactive(createDraftState(initial()));
    const controller = createDraftController(state, groupDraftApi, () => crypto.randomUUID(), confirmed);

    watch(initial, (draft) => {
        // Our own safe history update must not replace current edits or in-memory secrets.
        if (draft?.id === state.saved?.id && draft?.version === state.saved?.version
            && draft?.status === state.saved?.status && draft?.updated_at === state.saved?.updated_at) return;
        controller.restore(draft);
    });
    onBeforeUnmount(controller.dispose);

    return {
        state, ...controller,
        saved: computed(() => isDraftSaved(state)),
        busy: computed(() => state.operation !== "idle" || state.leaving),
        locked: computed(() => draftIsLocked(state)),
    };
}
