@if (session('status'))
    <div class="flash" role="status" style="margin: 0 0 20px;">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="flash flash-error" role="alert" style="margin: 0 0 20px;">{{ session('error') }}</div>
@endif
