function formatPhoneNumber(value) {
    const digits = value.replace(/\D/g, '').slice(0, 10);

    if (digits.length <= 3) {
        return digits;
    }

    if (digits.length <= 6) {
        return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
    }

    return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
}

document.querySelectorAll('.phone-input').forEach((input) => {
    input.addEventListener('input', () => {
        input.value = formatPhoneNumber(input.value);
    });

    input.addEventListener('blur', () => {
        input.value = formatPhoneNumber(input.value);
    });
});

function cleanVin(value) {
    return value.toUpperCase().replace(/\s+/g, '');
}

function vinWarningMessage(value) {
    if (!value) {
        return '';
    }

    if (!/^[A-Z0-9]+$/.test(value)) {
        return 'Standard VINs use only letters and numbers.';
    }

    if (value.length !== 17) {
        return 'Standard VINs are 17 characters. Older, homemade, off-road, or state-assigned IDs may be different.';
    }

    if (/[IOQ]/.test(value)) {
        return 'Standard VINs do not use I, O, or Q.';
    }

    return '';
}

document.querySelectorAll('.vin-input').forEach((input) => {
    const warning = input.parentElement?.querySelector('.vin-warning');
    let duplicateCheckTimer;
    let duplicateMessage = '';

    const updateVinWarning = () => {
        input.value = cleanVin(input.value);
        if (warning) {
            warning.textContent = vinWarningMessage(input.value) || duplicateMessage;
        }
    };

    const checkDuplicateVin = async () => {
        const checkUrl = input.dataset.vinCheckUrl;
        duplicateMessage = '';

        if (!checkUrl || !input.value) {
            updateVinWarning();
            return;
        }

        const params = new URLSearchParams({ vin: input.value });
        if (input.dataset.currentRequestId) {
            params.set('current_id', input.dataset.currentRequestId);
        }

        try {
            const response = await fetch(`${checkUrl}?${params.toString()}`);
            const result = await response.json();

            if (result.duplicate) {
                duplicateMessage = `This VIN is already used on request #${result.request_id} for ${result.registrant_name} (${result.status}).`;
            }
        } catch (error) {
            duplicateMessage = '';
        }

        updateVinWarning();
    };

    const scheduleVinCheck = () => {
        window.clearTimeout(duplicateCheckTimer);
        duplicateCheckTimer = window.setTimeout(checkDuplicateVin, 250);
    };

    input.addEventListener('input', () => {
        updateVinWarning();
        scheduleVinCheck();
    });

    input.addEventListener('blur', () => {
        updateVinWarning();
        checkDuplicateVin();
    });

    updateVinWarning();
    scheduleVinCheck();
});
