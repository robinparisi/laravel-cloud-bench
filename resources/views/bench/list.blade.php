{{-- A fragment, not a document: Livewire injects its assets into anything with
     <html> and </body>, and it does so unevenly between the two modes, which
     would end up in the measurement. --}}
@foreach ($rows as $row)
    @if ($mode === \App\Bench\RenderMode::Component)
        <x-bench-row :row="$row" />
    @else
        <article>
            <h2>{{ $row['title'] }}</h2>
            <p>{{ $row['excerpt'] }}</p>
        </article>
    @endif
@endforeach
