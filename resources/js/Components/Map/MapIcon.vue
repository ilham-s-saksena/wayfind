<script setup>
/*
 * Lucide icons. ISC License. Copyright (c) Lucide Contributors.
 * Permission to use, copy, modify, and/or distribute this software for any purpose
 * with or without fee is hereby granted, provided that the above copyright notice
 * and this permission notice appear in all copies.
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
 * REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT,
 * INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM LOSS
 * OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR OTHER
 * TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF
 * THIS SOFTWARE.
 * Feather-derived icons. MIT License. Copyright (c) 2013-present Cole Bemis.
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions: The above copyright notice and this
 * permission notice shall be included in all copies or substantial portions of
 * the Software. THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO
 * EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR
 * OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
defineProps({ name: { type: String, required: true } });
const paths = {
    search: ['m21 21-4.34-4.34', 'M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0'],
    pin: ['M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z', 'M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0'],
    arrow: ['M7 7h10v10', 'M7 17 17 7'],
    back: ['m12 19-7-7 7-7', 'M5 12h14'],
    chevron: ['m9 18 6-6-6-6'],
    down: ['m6 9 6 6 6-6'],
    close: ['m18 6-12 12', 'm6 6 12 12'],
    locate: ['M3 12h3m12 0h3M12 3v3m0 12v3', 'M19 12a7 7 0 1 1-14 0 7 7 0 0 1 14 0', 'M14 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0'],
    plus: ['M12 5v14', 'M5 12h14'],
    minus: ['M5 12h14'],
    layers: ['m12 3 10 6-10 6L2 9l10-6Z', 'm2 15 10 6 10-6', 'm2 12 10 6 10-6'],
    navigation: ['m3 11 19-9-9 19-2-8-8-2Z'],
    route: ['M4 17a3 3 0 1 0 0 .01', 'M20 4a3 3 0 1 0 0 .01', 'M4 14V7a3 3 0 0 1 3-3h5m8 3v10a3 3 0 0 1-3 3h-5'],
    walk: ['M15 4a2 2 0 1 1-4 0 2 2 0 0 1 4 0', 'm7 21 3-5', 'm16 21-2-4-3-3 1-6', 'M7 12V9l5-1 4 4h4'],
    check: ['m5 12 4 4L19 6'],
    info: ['M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0', 'M12 16v-4', 'M12 8h.01'],
    clock: ['M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0', 'M12 6v6l4 2'],
    building: ['M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18', 'M6 12H4a2 2 0 0 0-2 2v8h20v-6a2 2 0 0 0-2-2h-2', 'M10 6h4m-4 4h4m-4 4h4m-4 4h4'],
    expand: ['M8 3H5a2 2 0 0 0-2 2v3m13-5h3a2 2 0 0 1 2 2v3M3 16v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3'],
};
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path v-for="(path, index) in paths[name] || paths.pin" :key="index" :d="path" />
    </svg>
</template>
