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
