<template>
    <div class="searchable-select" ref="selectContainer">
        <div class="select-input-wrapper" @click="toggleDropdown">
            <input
                type="text"
                v-model="searchQuery"
                :placeholder="placeholder"
                class="dashboard-input"
                @focus="openDropdown"
                @input="onSearch"
                :disabled="disabled"
            />
            <span class="dropdown-arrow" :class="{ 'open': isOpen }">▼</span>
        </div>

        <div v-if="isOpen" class="dropdown-list">
            <div v-if="filteredOptions.length === 0" class="no-results">
                No results found
            </div>
            <div
                v-for="option in filteredOptions"
                :key="option"
                class="dropdown-item"
                :class="{ 'selected': modelValue === option }"
                @click="selectOption(option)"
            >
                {{ option }}
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';

// Props
const props = defineProps<{
    modelValue: string;
    options: string[];
    placeholder?: string;
    disabled?: boolean;
}>();

// Emits
const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

// Refs
const searchQuery = ref('');
const isOpen = ref(false);
const selectContainer = ref<HTMLElement | null>(null);

// Computed
const filteredOptions = computed(() => {
    if (!searchQuery.value) {
        return props.options;
    }
    return props.options.filter(option =>
        option.toLowerCase().includes(searchQuery.value.toLowerCase())
    );
});

// Methods
const toggleDropdown = () => {
    if (!props.disabled) {
        isOpen.value = !isOpen.value;
    }
};

const openDropdown = () => {
    if (!props.disabled) {
        isOpen.value = true;
    }
};

const selectOption = (option: string) => {
    emit('update:modelValue', option);
    searchQuery.value = option;
    isOpen.value = false;
};

const onSearch = () => {
    isOpen.value = true;
};

const handleClickOutside = (event: MouseEvent) => {
    if (selectContainer.value && !selectContainer.value.contains(event.target as Node)) {
        isOpen.value = false;
    }
};

// Lifecycle
onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    // Set initial search query to selected value
    if (props.modelValue) {
        searchQuery.value = props.modelValue;
    }
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

// Watch for external changes to modelValue
import { watch } from 'vue';
watch(() => props.modelValue, (newValue) => {
    if (newValue && newValue !== searchQuery.value) {
        searchQuery.value = newValue;
    }
});
</script>

<style scoped>
.searchable-select {
    position: relative;
    width: 100%;
}

.select-input-wrapper {
    position: relative;
    cursor: pointer;
}

.select-input-wrapper input {
    width: 100%;
    cursor: pointer;
    padding-right: 35px;
}

.dropdown-arrow {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    transition: transform 0.2s;
    color: #6c757d;
    font-size: 12px;
}

.dropdown-arrow.open {
    transform: translateY(-50%) rotate(180deg);
}

.dropdown-list {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 300px;
    overflow-y: auto;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    margin-top: 4px;
    z-index: 1000;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.dropdown-item {
    padding: 10px 12px;
    cursor: pointer;
    transition: background-color 0.15s;
}

.dropdown-item:hover {
    background-color: #f8f9fa;
}

.dropdown-item.selected {
    background-color: #e7f3ff;
    color: #0d6efd;
    font-weight: 500;
}

.no-results {
    padding: 10px 12px;
    color: #6c757d;
    text-align: center;
}

/* Scrollbar styling */
.dropdown-list::-webkit-scrollbar {
    width: 8px;
}

.dropdown-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.dropdown-list::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}

.dropdown-list::-webkit-scrollbar-thumb:hover {
    background: #555;
}
</style>

