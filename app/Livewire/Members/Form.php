<?php

namespace App\Livewire\Members;

use App\Livewire\Members\Concerns\FindsMember;
use App\Models\Household;
use App\Models\Member;
use App\Models\MemberField;
use App\Models\MemberStatusChange;
use App\Models\Organization;
use App\Services\MemberRegistry;
use App\Support\MemberPhoto;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Ajout et modification d'un membre, avec détection des doublons. */
class Form extends Component
{
    use FindsMember, WithFileUploads;

    public ?Member $member = null;

    /** Champs de la fiche (colonnes de la table members). */
    public array $data = [
        'last_name' => '', 'middle_name' => '', 'first_name' => '', 'gender' => '',
        'birth_date' => '', 'birth_place' => '',
        'phone' => '', 'phone2' => '', 'email' => '', 'preferred_language' => '',
        'district' => '', 'street' => '', 'house_number' => '', 'city' => '',
        'profession' => '', 'marital_status' => '', 'education_level' => '', 'origin_church' => '',
        'emergency_contact_name' => '', 'emergency_contact_phone' => '',
        'joined_on' => '', 'status_id' => '', 'notes' => '',
    ];

    /** Valeurs des champs ajoutés par la communauté, par clé. */
    public array $custom = [];

    /** Numéro repris d'un ancien registre (à l'ajout seulement). */
    public string $existingNumber = '';

    public $photo = null;

    public bool $removePhoto = false;

    // Changement de statut
    public ?int $originalStatusId = null;

    public string $statusReason = '';

    // Ménage (à l'ajout)
    public string $householdMode = 'none'; // none, existing, new

    public string $householdSearch = '';

    public ?int $householdId = null;

    public string $householdRole = 'head';

    public bool $confirmDuplicate = false;

    public function mount(?int $id = null): void
    {
        if ($id) {
            $this->member = $this->findMember($id, 'members.manage');
            $this->fill(['data' => array_merge($this->data, collect($this->member->only(array_keys($this->data)))
                ->map(fn ($v, $k) => match (true) {
                    $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
                    in_array($k, ['phone', 'phone2', 'emergency_contact_phone']) => Phone::format($v),
                    default => (string) $v,
                })->all())]);
            $this->custom = $this->member->custom ?? [];
            $this->originalStatusId = $this->member->status_id;
        } else {
            $this->authorize('members.manage');
            $organization = current_organization();
            $this->data['status_id'] = (string) app(MemberRegistry::class)->defaultStatus($organization)?->id;
            $this->data['city'] = (string) $organization->city;
            $this->data['joined_on'] = now()->format('Y-m-d');
        }
    }

    protected function organization(): Organization
    {
        return $this->member?->organization ?? current_organization();
    }

    public function updatedHouseholdMode(): void
    {
        $this->householdId = null;
        $this->householdRole = $this->householdMode === 'new' ? 'head' : 'child';
    }

    /** Une nouvelle saisie du nom ou du téléphone relance la recherche de doublons. */
    public function updatedData($value, string $key): void
    {
        if (in_array($key, ['last_name', 'first_name', 'phone'], true)) {
            $this->confirmDuplicate = false;
        }
    }

    public function chooseHousehold(int $id): void
    {
        $this->householdId = Household::findOrFail($id)->id;
        $this->householdSearch = '';
    }

    /** Membres qui ressemblent à la saisie : même nom et prénom, ou même téléphone. */
    public function duplicates(): Collection
    {
        $last = Str::lower(Str::ascii(trim($this->data['last_name'])));
        $first = Str::lower(Str::ascii(trim($this->data['first_name'])));
        $phone = Phone::normalize($this->data['phone'] ?: null);

        if ($last === '' && ! $phone) {
            return collect();
        }

        $ids = Organization::query()->subtreeOf($this->organization()->root())->pluck('id');

        return Member::withoutOrganizationScope()->whereIn('organization_id', $ids)
            ->when($this->member, fn ($q) => $q->whereKeyNot($this->member->id))
            ->where(function ($q) use ($last, $first, $phone) {
                if ($last !== '') {
                    $q->where(function ($q) use ($last, $first) {
                        $q->whereRaw('LOWER(last_name) = ?', [$last]);
                        if ($first !== '') {
                            $q->where(fn ($q) => $q->whereRaw('LOWER(first_name) = ?', [$first])->orWhereRaw('LOWER(middle_name) = ?', [$first]));
                        }
                    });
                }
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->with(['organization', 'status'])->limit(5)->get();
    }

    protected function rules(): array
    {
        $registry = app(MemberRegistry::class);
        $organization = $this->organization();
        $phone = fn ($attribute, $value, $fail) => $value && ! Phone::normalize($value) ? $fail(__('Ce numéro de téléphone n’est pas valide.')) : null;

        $rules = [
            'data.last_name' => 'required|string|max:80',
            'data.middle_name' => 'nullable|string|max:80',
            'data.first_name' => 'nullable|string|max:80',
            'data.gender' => 'nullable|in:M,F',
            'data.birth_date' => 'nullable|date|before_or_equal:today|after:1900-01-01',
            'data.birth_place' => 'nullable|string|max:100',
            'data.phone' => ['nullable', 'string', 'max:25', $phone],
            'data.phone2' => ['nullable', 'string', 'max:25', $phone],
            'data.email' => 'nullable|email|max:255',
            'data.preferred_language' => ['nullable', Rule::in(array_keys(config('waumini.locales')))],
            'data.district' => 'nullable|string|max:100',
            'data.street' => 'nullable|string|max:120',
            'data.house_number' => 'nullable|string|max:30',
            'data.city' => 'nullable|string|max:100',
            'data.profession' => 'nullable|string|max:100',
            'data.marital_status' => ['nullable', Rule::in(array_keys(Member::MARITAL_STATUSES))],
            'data.education_level' => 'nullable|string|max:60',
            'data.origin_church' => 'nullable|string|max:150',
            'data.emergency_contact_name' => 'nullable|string|max:120',
            'data.emergency_contact_phone' => ['nullable', 'string', 'max:25', $phone],
            'data.joined_on' => 'nullable|date|before_or_equal:today',
            'data.status_id' => ['nullable', Rule::in($registry->statuses($organization)->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'data.notes' => 'nullable|string|max:5000',
            'photo' => 'nullable|image|max:5120',
            'existingNumber' => ['nullable', 'string', 'max:60', Rule::unique('members', 'number')->where('organization_id', $organization->id)],
            'householdRole' => ['required', Rule::in(array_keys(Household::ROLES))],
            'householdId' => [Rule::requiredIf($this->householdMode === 'existing')],
        ];

        foreach ($this->editableFields() as $field) {
            $rules['custom.'.$field->key] = $field->rules();
        }

        return $rules;
    }

    protected function validationAttributes(): array
    {
        return [
            'data.last_name' => __('nom'), 'data.first_name' => __('prénom'), 'data.middle_name' => __('post-nom'),
            'data.birth_date' => __('date de naissance'), 'data.phone' => __('téléphone'), 'data.email' => __('e-mail'),
            'data.joined_on' => __('date d’adhésion'), 'existingNumber' => __('numéro'), 'householdId' => __('ménage'),
        ] + $this->editableFields()->mapWithKeys(fn ($f) => ['custom.'.$f->key => mb_strtolower($f->label)])->all();
    }

    /** Champs ajoutés que l'utilisateur peut voir et modifier. */
    private function editableFields(): Collection
    {
        $canSensitive = Gate::allows('members.sensitive', $this->organization());

        return app(MemberRegistry::class)->fields($this->organization())->filter(fn (MemberField $f) => $canSensitive || ! $f->sensitive)->values();
    }

    public function save(MemberRegistry $registry)
    {
        $organization = $this->organization();
        abort_unless(Gate::allows('members.manage', $organization), 403);
        abort_if($organization->isReadOnly(), 403);

        $this->validate();

        if (! $this->confirmDuplicate && $this->duplicates()->isNotEmpty()) {
            $this->confirmDuplicate = true; // le prochain clic enregistre quand même
            $this->addError('duplicates', __('Cette personne est peut-être déjà inscrite. Vérifiez la liste ci-dessous.'));

            return null;
        }

        $canSensitive = Gate::allows('members.sensitive', $organization);
        $hidden = $registry->settings($organization)['hidden_fields'];
        // Seules les clés du formulaire : le navigateur pourrait en ajouter d'autres (organisation, photo…).
        $data = collect($this->data)->only(array_keys((new \ReflectionProperty(self::class, 'data'))->getDefaultValue()))
            ->map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v));

        // Les champs masqués par le siège et les notes protégées ne sont pas modifiés.
        $skip = collect($hidden)->flatMap(fn ($f) => $f === 'emergency_contact' ? ['emergency_contact_name', 'emergency_contact_phone'] : [$f]);
        if (! $canSensitive) {
            $skip->push('notes');
        }
        $data = $data->except($skip->all());
        $data['last_name'] = Str::of($data['last_name'])->squish()->upper()->toString();

        // Valeurs des champs ajoutés : on garde celles que l'utilisateur ne voit pas.
        $custom = array_merge($this->member?->custom ?? [], collect($this->custom)
            ->only($this->editableFields()->pluck('key'))
            ->map(fn ($v) => $v === '' ? null : $v)->all());

        $member = DB::transaction(function () use ($data, $custom, $organization, $registry) {
            $member = $this->member ?? new Member(['organization_id' => $organization->id, 'created_by' => auth()->id()]);
            $member->fill($data->all() + ['custom' => array_filter($custom, fn ($v) => $v !== null) ?: null]);
            $member->save();

            if (! $this->member) {
                if (trim($this->existingNumber) !== '') {
                    $member->forceFill(['number' => trim($this->existingNumber)])->save();
                } else {
                    $registry->assignNumber($member);
                }
            }

            $statusChanged = $this->member ? (int) $this->originalStatusId !== (int) $member->status_id : (bool) $member->status_id;
            if ($statusChanged) {
                MemberStatusChange::create([
                    'member_id' => $member->id,
                    'from_status_id' => $this->member ? $this->originalStatusId : null,
                    'to_status_id' => $member->status_id,
                    'changed_on' => $this->member ? now() : ($member->joined_on ?? now()),
                    'reason' => $this->member ? (trim($this->statusReason) ?: null) : __('Inscription'),
                    'user_id' => auth()->id(),
                ]);
            }

            if (! $this->member && $this->householdMode !== 'none') {
                $household = $this->householdMode === 'new'
                    ? Household::create([
                        'organization_id' => $organization->id,
                        'name' => __('Famille :name', ['name' => $member->last_name]),
                        ...$member->only(['district', 'street', 'house_number', 'city']),
                        'phone' => $member->phone,
                    ])
                    : Household::withoutOrganizationScope()->where('organization_id', $organization->id)->findOrFail($this->householdId);

                $member->update(['household_id' => $household->id, 'household_role' => $this->householdRole]);
                if ($this->householdRole === 'head' && ! $household->head_member_id) {
                    $household->update(['head_member_id' => $member->id]);
                }
            }

            $this->savePhoto($member);

            return $member;
        });

        session()->flash('status', $this->member ? __('Fiche enregistrée.') : __(':name est inscrit(e) sous le numéro :number.', ['name' => $member->fullName(), 'number' => $member->number]));

        return $this->redirectRoute('members.show', $member, navigate: false);
    }

    private function savePhoto(Member $member): void
    {
        if ($this->removePhoto && $member->photo_path) {
            MemberPhoto::delete($member->photo_path);
            $member->update(['photo_path' => null]);
        }

        if ($this->photo) {
            $old = $member->photo_path;
            $member->update(['photo_path' => MemberPhoto::store($this->photo->getRealPath(), $member)]);
            if ($old) {
                MemberPhoto::delete($old);
            }
        }
    }

    public function render(MemberRegistry $registry)
    {
        $organization = $this->organization();
        $settings = $registry->settings($organization);
        $households = collect();

        if (! $this->member && $this->householdMode === 'existing' && trim($this->householdSearch) !== '') {
            $term = '%'.trim($this->householdSearch).'%';
            $households = Household::withoutOrganizationScope()->where('organization_id', $organization->id)
                ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('district', 'like', $term))
                ->withCount('members')->orderBy('name')->limit(6)->get();
        }

        return view('livewire.members.form', [
            'organization' => $organization,
            'hidden' => $settings['hidden_fields'],
            'statuses' => $registry->statuses($organization),
            'fields' => $this->editableFields(),
            'canSensitive' => Gate::allows('members.sensitive', $organization),
            'duplicates' => $this->getErrorBag()->has('duplicates') ? $this->duplicates() : collect(),
            'households' => $households,
            'chosenHousehold' => $this->householdId ? Household::withoutOrganizationScope()->where('organization_id', $this->organization()->id)->find($this->householdId) : null,
            'nextNumber' => $this->member ? null : $registry->format($settings, $organization, (int) now()->format('Y'),
                max((int) Member::withoutOrganizationScope()->withTrashed()->where('organization_id', $organization->id)
                    ->when($settings['yearly_reset'], fn ($q) => $q->where('number_year', now()->year))->max('number_sequence') + 1, (int) $settings['start_number'])),
            'statusChanged' => $this->member && (string) $this->originalStatusId !== (string) $this->data['status_id'],
        ])->title($this->member ? __('Modifier :name', ['name' => $this->member->fullName()]) : __('Ajouter un membre'));
    }
}
