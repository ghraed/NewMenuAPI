<h2>New Rozer contact lead</h2>

<p><strong>Name:</strong> {{ $lead->name ?? 'N/A' }}</p>
<p><strong>Phone:</strong> {{ $lead->phone ?? 'N/A' }}</p>
<p><strong>Email:</strong> {{ $lead->email ?? 'N/A' }}</p>
<p><strong>Business type:</strong> {{ $lead->business_type ?? 'N/A' }}</p>
<p><strong>Preferred contact method:</strong> {{ $lead->preferred_contact_method ?? 'N/A' }}</p>
<p><strong>Latest user message:</strong> {{ $lead->message ?? 'N/A' }}</p>
<p><strong>Conversation summary:</strong> {!! nl2br(e($lead->conversation_summary ?? 'N/A')) !!}</p>
<p><strong>Source page:</strong> {{ $lead->source_page ?? 'N/A' }}</p>
<p><strong>Created date:</strong> {{ optional($lead->created_at)->toDateTimeString() ?? 'N/A' }}</p>
