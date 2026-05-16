<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class PublicSdkDocsController extends Controller
{
    public function index(): View
    {
        return view('sdk.public', [
            'pageTitle' => 'PBB Realtime SDK Docs',
            'eyebrow' => 'SDK Docs',
            'title' => 'PBB Realtime SDK reference',
            'lede' => 'Public reference surface for the frontend SDK, backend SDK, and example demos.',
            'active' => 'overview',
            'referenceDocs' => $this->referenceDocs(),
            'tutorials' => $this->tutorials(),
            'mode' => 'overview',
        ]);
    }

    public function indexJson(): JsonResponse
    {
        $referenceDocs = $this->referenceDocs();
        $tutorials = $this->tutorials();

        return response()->json([
            'status' => true,
            'data' => [
                'title' => 'PBB Realtime SDK Docs',
                'base_url' => url('/sdk-docs'),
                'reference_docs' => collect($referenceDocs)->map(function (array $doc, string $id) {
                    return [
                        'id' => $id,
                        'title' => $doc['title'],
                        'group' => $doc['group'],
                        'summary' => $doc['summary'],
                        'html_url' => route('sdk.public.reference', ['doc' => $id]),
                        'markdown_url' => route('sdk.docs.public.show', ['doc' => $id]),
                    ];
                })->values(),
                'tutorials' => collect($tutorials)->map(function (array $tutorial, string $slug) {
                    return [
                        'slug' => $slug,
                        'title' => $tutorial['title'],
                        'summary' => $tutorial['summary'],
                        'html_url' => route('sdk.public.tutorials.show', ['tutorial' => $slug]),
                        'demo_url' => url($tutorial['demo_url']),
                        'reference_docs' => $tutorial['reference_docs'],
                    ];
                })->values(),
            ],
        ]);
    }

    public function sitemap(): Response
    {
        $urls = [
            route('sdk.public.index'),
            route('sdk.public.backend'),
        ];

        foreach (array_keys($this->tutorials()) as $slug) {
            $urls[] = route('sdk.public.tutorials.show', ['tutorial' => $slug]);
        }

        foreach (array_keys($this->referenceDocs()) as $id) {
            $urls[] = route('sdk.public.reference', ['doc' => $id]);
            $urls[] = route('sdk.docs.public.show', ['doc' => $id]);
        }

        $xml = view('sdk.sitemap', [
            'urls' => $urls,
        ])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function backend(): View
    {
        return view('sdk.public', [
            'pageTitle' => 'PBB Realtime Backend SDK Docs',
            'eyebrow' => 'Backend SDK',
            'title' => 'PBB Realtime backend SDK reference',
            'lede' => 'Public reference surface for trusted admission issuance from product backends.',
            'active' => 'backend',
            'referenceDocs' => $this->referenceDocs(),
            'tutorials' => $this->tutorials(),
            'mode' => 'backend',
        ]);
    }

    public function tutorial(string $tutorial): View|RedirectResponse
    {
        $item = $this->tutorials()[$tutorial] ?? null;
        if (! $item) {
            return redirect()->route('sdk.public.index');
        }

        return view('sdk.tutorial', [
            'pageTitle' => sprintf('%s | PBB Realtime SDK Docs', $item['title']),
            'tutorial' => $item,
            'active' => $tutorial,
            'referenceDocs' => $this->referenceDocs(),
            'tutorials' => $this->tutorials(),
        ]);
    }

    public function reference(string $doc): View|RedirectResponse
    {
        $selected = $this->referenceDocs()[$doc] ?? null;
        if (! $selected) {
            return redirect()->route('sdk.public.index');
        }

        $markdown = @file_get_contents($selected['path']);
        if ($markdown === false) {
            abort(404);
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return view('sdk.reference', [
            'pageTitle' => sprintf('%s | PBB Realtime SDK Docs', $selected['title']),
            'title' => $selected['title'],
            'eyebrow' => Arr::get($selected, 'group', 'Reference'),
            'html' => (string) $converter->convert($markdown),
            'markdownUrl' => route('sdk.docs.public.show', ['doc' => $doc]),
            'active' => $doc,
            'referenceDocs' => $this->referenceDocs(),
            'tutorials' => $this->tutorials(),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function referenceDocs(): array
    {
        return [
            'integration-guide' => [
                'title' => 'Integration Guide',
                'group' => 'Frontend SDK',
                'path' => base_path('docs/pbb-realtime-sdk-integration-guide.md'),
                'summary' => 'How product teams should integrate the frontend SDK.',
            ],
            'hotline-reference-flow' => [
                'title' => 'Hotline Reference Flow',
                'group' => 'Frontend SDK',
                'path' => base_path('docs/pbb-realtime-sdk-hotline-reference-flow.md'),
                'summary' => 'Reference transport flow for Hotline-style product behavior.',
            ],
            'product-query-forwarding' => [
                'title' => 'Product Query Forwarding',
                'group' => 'Frontend SDK',
                'path' => base_path('docs/pbb-realtime-product-query-forwarding-spec.md'),
                'summary' => 'Allowlisted websocket-to-product-backend query forwarding contract.',
            ],
            'versioning-strategy' => [
                'title' => 'Versioning Strategy',
                'group' => 'Frontend SDK',
                'path' => base_path('docs/pbb-realtime-sdk-versioning-strategy.md'),
                'summary' => 'Compatibility expectations and release discipline for the SDK.',
            ],
            'backend-sdk-proposal' => [
                'title' => 'Backend SDK Proposal',
                'group' => 'Backend SDK',
                'path' => base_path('docs/pbb-realtime-backend-sdk-proposal.md'),
                'summary' => 'Why the backend SDK exists and what it should own.',
            ],
            'backend-sdk-checklist' => [
                'title' => 'Backend SDK Checklist',
                'group' => 'Backend SDK',
                'path' => base_path('docs/pbb-realtime-backend-sdk-implementation-checklist.md'),
                'summary' => 'Implementation and verification checklist for the backend SDK.',
            ],
            'backend-sdk-quickstart' => [
                'title' => 'Backend SDK Quickstart',
                'group' => 'Backend SDK',
                'path' => base_path('docs/pbb-realtime-backend-sdk-quickstart.md'),
                'summary' => 'Plain-PHP quickstart for product backend admission issuance.',
            ],
            'backend-sdk-hotline-example' => [
                'title' => 'Backend SDK Hotline Example',
                'group' => 'Backend SDK',
                'path' => base_path('docs/pbb-realtime-backend-sdk-hotline-example.md'),
                'summary' => 'Concrete backend admission example for Hotline-like projects.',
            ],
            'backend-sdk-arguments-reference' => [
                'title' => 'Backend SDK Arguments Reference',
                'group' => 'Backend SDK',
                'path' => base_path('docs/pbb-realtime-backend-sdk-arguments-reference.md'),
                'summary' => 'Input contracts for backend SDK functions.',
            ],
            'backend-sdk-return-values-reference' => [
                'title' => 'Backend SDK Return Values Reference',
                'group' => 'Backend SDK',
                'path' => base_path('docs/pbb-realtime-backend-sdk-return-values-reference.md'),
                'summary' => 'Output contracts for backend SDK functions.',
            ],
            'backend-sdk-trust-boundary' => [
                'title' => 'Backend SDK Trust Boundary',
                'group' => 'Backend SDK',
                'path' => base_path('docs/pbb-realtime-backend-sdk-trust-boundary.md'),
                'summary' => 'Responsibility split across product backend, frontend SDK, and Realtime.',
            ],
            'backend-sdk-migration-guide' => [
                'title' => 'Backend SDK Migration Guide',
                'group' => 'Backend SDK',
                'path' => base_path('docs/pbb-realtime-backend-sdk-migration-guide.md'),
                'summary' => 'How to replace hand-built token code gradually.',
            ],
            'sdk-demo-app' => [
                'title' => 'SDK Demo App',
                'group' => 'Demos',
                'path' => base_path('docs/pbb-realtime-sdk-demo-app.md'),
                'summary' => 'Simple PHP + JS demo app built on the SDK and Helper.',
            ],
            'sdk-demo-attachments-app' => [
                'title' => 'SDK Attachment Demo App',
                'group' => 'Demos',
                'path' => base_path('docs/pbb-realtime-sdk-demo-attachments-app.md'),
                'summary' => 'Attachment transport demo reference.',
            ],
            'sdk-demo-conference-app' => [
                'title' => 'SDK Conference Demo App',
                'group' => 'Demos',
                'path' => base_path('docs/pbb-realtime-sdk-demo-conference-app.md'),
                'summary' => 'Conference and mesh signaling demo reference.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function tutorials(): array
    {
        return [
            'quickstart' => [
                'title' => 'Quickstart',
                'summary' => 'Bring up a basic Realtime client with admission, connection, room join, presence, chat, and optional media transport.',
                'bullets' => [
                    'Call your product backend first to obtain the signed Realtime admission payload.',
                    'Construct RealtimeSocketClient with websocket URL and token.',
                    'Connect, wait for auth ack, join the room, then subscribe and publish presence.',
                    'Layer in chat, attachments, media chunk transport, and conference only when the product needs them.',
                ],
                'reference_docs' => ['integration-guide', 'backend-sdk-quickstart', 'sdk-demo-app'],
                'demo_url' => '/sdk-demo/',
            ],
            'presence' => [
                'title' => 'Presence Tutorial',
                'summary' => 'Maintain a stable room roster and publish state changes safely.',
                'bullets' => [
                    'Treat presence as reducer-managed transport state, not as product business state.',
                    'Subscribe after room join ack, then publish the local peer state.',
                    'Render roster items from the reduced store instead of mutating UI state ad hoc.',
                ],
                'reference_docs' => ['integration-guide', 'sdk-demo-app'],
                'demo_url' => '/sdk-demo/',
            ],
            'chat' => [
                'title' => 'Chat Tutorial',
                'summary' => 'Publish chat messages and normalize incoming events into a terminal-friendly UI model.',
                'bullets' => [
                    'Use buildChatPublishPayload for outbound messages.',
                    'Use normalizeChatMessageEvent before handing inbound events to the UI layer.',
                    'Keep optimistic UI behavior in the product app, not inside the SDK core.',
                ],
                'reference_docs' => ['integration-guide', 'sdk-demo-app'],
                'demo_url' => '/sdk-demo/',
            ],
            'attachments' => [
                'title' => 'Attachment Transport Tutorial',
                'summary' => 'Validate files, chunk them, transfer them, and reassemble them safely.',
                'bullets' => [
                    'Validate against attachment policy before sending.',
                    'Use transferAttachmentInChunks for chunk publishing and progress callbacks.',
                    'Use the reducer/store helpers on the receiving side to rebuild attachment state.',
                ],
                'reference_docs' => ['integration-guide', 'sdk-demo-attachments-app'],
                'demo_url' => '/sdk-demo-attachments/',
            ],
            'media' => [
                'title' => 'Media Transport Tutorial',
                'summary' => 'Publish browser-originated media chunks over websocket while keeping storage and lifecycle in the product backend.',
                'bullets' => [
                    'Enable media ingest on the target project scope and use the correct media room prefix such as `call.session.` or `stream.session.`.',
                    'Join the authorized room first, then publish `media.chunk.publish` using `buildMediaChunkPublishPayload(...)`.',
                    'Treat the immediate ack as queue acceptance only; wait for `media.chunk.forwarded` before deleting local retry data.',
                    'Handle `media.chunk.failed` as the downstream ingest failure signal for retry or product-owned error state.',
                    'Keep temp storage, merge/finalize, and media business events in the product backend rather than in Realtime.',
                ],
                'reference_docs' => ['integration-guide', 'hotline-reference-flow', 'sdk-demo-conference-app'],
                'demo_url' => '/sdk-demo-conference/',
            ],
            'conference' => [
                'title' => 'Call And Conference Tutorial',
                'summary' => 'Run signaling and small-group mesh conference behavior using the SDK conference helpers.',
                'bullets' => [
                    'Keep per-remote RTCPeerConnection and MediaStream maps.',
                    'Use targeted offer/answer/ICE signaling per remote participant.',
                    'Treat this as small-group mesh reference behavior, not as SFU infrastructure.',
                ],
                'reference_docs' => ['integration-guide', 'hotline-reference-flow', 'sdk-demo-conference-app'],
                'demo_url' => '/sdk-demo-conference/',
            ],
        ];
    }
}
