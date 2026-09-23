import { onBeforeUnmount, ref, watch } from 'vue';

export type LookupState = 'idle' | 'busy' | 'found' | 'failed';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The name on a bank account, looked up through Paystack as soon as a bank
 * and all ten digits are in. It is never typed: the server looks it up again
 * on save, so this is only for the person to see the account is the right one.
 */
export function useAccountLookup(
    source: () => readonly [string | null, string | null],
    initialName: string | null = null,
    url = '/bank-accounts/resolve',
) {
    const accountName = ref<string | null>(initialName);
    const lookup = ref<LookupState>(initialName ? 'found' : 'idle');
    const lookupError = ref<string | null>(null);

    let timer: ReturnType<typeof setTimeout> | undefined;
    let asked = 0;

    async function resolve(code: string, number: string) {
        const ticket = ++asked;
        lookup.value = 'busy';
        lookupError.value = null;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({
                    bank_code: code,
                    account_number: number,
                }),
            });
            const body = await response.json().catch(() => ({}));

            // A later keystroke has already asked again; this answer is stale.
            if (ticket !== asked) {
                return;
            }

            if (response.ok && body.account_name) {
                accountName.value = body.account_name;
                lookup.value = 'found';

                return;
            }

            accountName.value = null;
            lookup.value = 'failed';
            lookupError.value =
                response.status === 429
                    ? 'Too many lookups. Wait a minute and try again.'
                    : (body.message ??
                      body.errors?.account_number?.[0] ??
                      'We could not check this account just now.');
        } catch {
            if (ticket === asked) {
                accountName.value = null;
                lookup.value = 'failed';
                lookupError.value =
                    'We could not reach the bank. Check your connection.';
            }
        }
    }

    watch(source, ([code, number], [oldCode, oldNumber]) => {
        if (code === oldCode && number === oldNumber) {
            return;
        }

        clearTimeout(timer);
        asked++;
        accountName.value = null;
        lookupError.value = null;
        lookup.value = 'idle';

        if (code && number && /^\d{10}$/.test(number)) {
            timer = setTimeout(() => resolve(code, number), 400);
        }
    });

    onBeforeUnmount(() => clearTimeout(timer));

    return { accountName, lookup, lookupError };
}
