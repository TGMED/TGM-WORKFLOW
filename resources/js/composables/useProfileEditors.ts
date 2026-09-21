import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { EmployeeAddress, EmployeeRelation, RelationKind } from '@/types';

/**
 * The relation and address modals, shared by the profile page and the guided
 * setup so the two edit a record the same way. Null means adding a new one.
 */
export function useProfileEditors() {
    const relationOpen = ref(false);
    const relationKind = ref<RelationKind>('next_of_kin');
    const editingRelation = ref<EmployeeRelation | null>(null);

    const addressOpen = ref(false);
    const editingAddress = ref<EmployeeAddress | null>(null);

    function addRelation(kind: RelationKind) {
        relationKind.value = kind;
        editingRelation.value = null;
        relationOpen.value = true;
    }

    function editRelation(relation: EmployeeRelation) {
        relationKind.value = relation.kind;
        editingRelation.value = relation;
        relationOpen.value = true;
    }

    function removeRelation() {
        if (!editingRelation.value) {
            return;
        }

        router.delete(`/profile/relations/${editingRelation.value.id}`, {
            preserveScroll: true,
            onSuccess: () => (relationOpen.value = false),
        });
    }

    function editAddress(address: EmployeeAddress | null) {
        editingAddress.value = address;
        addressOpen.value = true;
    }

    function removeAddress() {
        if (!editingAddress.value) {
            return;
        }

        router.delete(`/profile/addresses/${editingAddress.value.id}`, {
            preserveScroll: true,
            onSuccess: () => (addressOpen.value = false),
        });
    }

    return {
        relationOpen,
        relationKind,
        editingRelation,
        addressOpen,
        editingAddress,
        addRelation,
        editRelation,
        removeRelation,
        editAddress,
        removeAddress,
    };
}
