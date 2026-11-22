{{--
    Renders a list of blocks (home page, a page template or a campaign page). Each block can be
    limited to computers or phones (its "visibility" setting); the wrapper has display: contents,
    so it doesn't change the layout.
--}}
@foreach ($blocks as $section)
    <div class="blk vis-{{ $section['settings']['visibility'] ?? 'all' }}">
        @includeIf($theme->view('sections.'.$section['type']), ['section' => $section, 's' => $section['settings']])
    </div>
@endforeach
