@php
$config = [
    'max' => $attributes->get('max') ?? 5,
    'accept' => $attributes->get('accept'),
    'multiple' => $attributes->get('multiple'),
    'visibility' => $attributes->get('visibility'),
    'optimization' => $attributes->get('optimization') ?? [],
    'route' => route('__filesystem.upload'),
];
@endphp

<div
x-data="{
    @if ($attributes->wire('model')->value())
    value: @entangle($attributes->wire('model')),
    @else
    value: null,
    @endif
    config: @js($config),
    jobs: [],
    loading: false,
    progress: null,

    read (files) {
        this.jobs = files.map(file => ({ file, done: false }))

        let validator = this.validate()
        
        if (validator.failed) {
            this.toast({ title: 'Validation Error', message: validator.error, type: 'error' })
        }
        else {
            this.loading = true

            this.upload()
                .then(res => {
                    this.value = res.id
                    this.$dispatch('uploaded', res.files)
                    Livewire.emit('uploaded', res.files)
                })
                .catch(({ message }) => this.toast({ title: 'Unable to Upload', message, type: 'error' }))
                .finally(() => {
                    this.jobs = []
                    this.loading = false
                    this.progress = null
                })
        }
    },

    upload () {
        let job = this.jobs.find(job => (!job.done))

        if (job) {
            let formdata = new FormData()

            formdata.append('file', job.file)

            if (this.config.path) formdata.append('settings[path]', this.config.path)
            if (this.config.visibility) formdata.append('settings[visibility]', this.config.visibility)
            if (this.config.optimization.width) formdata.append('settings[optimization][width]', this.config.optimization.width)
            if (this.config.optimization.height) formdata.append('settings[optimization][height]', this.config.optimization.height)
            if (this.config.optimization.quality) formdata.append('settings[optimization][quality]', this.config.optimization.quality)
            if (this.config.optimization.webp) formdata.append('settings[optimization][webp]', 1)

            return fetch(this.config.route, {
                method: 'POST',
                body: formdata,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector(`meta[name='csrf-token']`).getAttribute('content'),
                },
            }).then(res => (res.json())).then(res => {
                job.response = res
                job.done = true
                this.setProgress()
                return this.upload()
            })
        }
        // all jobs done, return final results
        else {
            return new Promise(resolve => {
                let files = this.jobs.map(job => (job.response)).flat()
                let id = files.map(file => (file.id))

                resolve({
                    files: this.config.multiple ? [...files] : files[0],
                    id: this.config.multiple ? [...id] : id[0],
                })
            })
        }
    },

    setProgress () {
        let count = this.jobs.length
        let done = this.jobs.filter(job => (job.done)).length
        this.progress = `${done}/${count}`
    },

    toast (data) {
        if (window.Atom) Atom.toast({ title: data.title, message: data.message }, data.type || 'info')
        else window.alert(data.message)
    },

    validate () {
        // scan for unsupported file type
        if (
            this.config.accept
            && this.jobs.some(job => {
                const accept = this.config.accept.split(',').map(val => (val.trim())).filter(Boolean)
                return accept.length && accept.some((val) => {
                    if (val.endsWith('*')) return !job.file.type.startsWith(val.replace('*', ''))
                    else if (val.startsWith('*')) return !job.file.type.endsWith(val.replace('*', ''))
                    else return !val.includes(job.file.type)
                })
            })
        ) {
            return { failed: true, error: 'Unsupported File Type' }
        }

        // scan for oversize file
        if (this.jobs.some(job => {
            const size = job.file.size/1024/1024
            return size >= this.config.max
        })) {
            return { failed: true, error: `File oversize. Max file size is ${this.config.max}MB` }
        }

        return { failed: false }
    },

    openFileInput () {
        if (this.loading) return
        this.$refs.fileInput.click()
    },
}"
x-modelable="value"
x-bind:class="loading && 'is-loading pointer-events-none'"
{{ $attributes->class(['relative'])->except(['max', 'accept', 'multiple', 'visibility', 'optimization']) }}>
    <div x-bind:class="loading && 'opacity-50'">
        <input 
        type="file"
        x-ref="fileInput"
        x-on:change="read(Array.from($event.target.files))"
        x-on:input.stop
        x-on:click.stop
        accept="{{ $config['accept'] }}"
        @if ($config['multiple'])
        multiple
        @endif
        class="hidden">

        {{ $slot }}
    </div>

    @isset ($loading)
        {{ $loading }}
    @else
        <div x-show="loading && progress" class="absolute bottom-2 right-2">
            <div x-text="progress" class="bg-black rounded-md text-sm text-white px-2"></div>
        </div>
    @endisset
</div>
