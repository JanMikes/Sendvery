import { Controller } from '@hotwired/stimulus'

const STORAGE_KEY = 'sendvery_pricing'

export default class extends Controller {
    static targets = ['card', 'price', 'strikethrough', 'billingNote', 'savingsChip', 'aiFeature', 'cta', 'aiToggle', 'billingButton']
    static values = {
        billing: { type: String, default: 'annual' },
        ai: { type: Boolean, default: false },
    }

    connect() {
        try {
            const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
            if (stored.billing === 'monthly' || stored.billing === 'annual') {
                this.billingValue = stored.billing
            }
            if (typeof stored.ai === 'boolean') {
                this.aiValue = stored.ai
            }
        } catch {
            // Ignore corrupt localStorage state.
        }

        this.aiToggleTargets.forEach((toggle) => {
            toggle.checked = this.aiValue
        })

        this.render()
    }

    setBilling(event) {
        const next = event.currentTarget.dataset.billing
        if (next === 'monthly' || next === 'annual') {
            this.billingValue = next
            this.persist()
            this.render()
        }
    }

    toggleAi(event) {
        this.aiValue = event.currentTarget.checked
        // Mirror across all toggles so they stay in sync if there's more than one.
        this.aiToggleTargets.forEach((toggle) => {
            toggle.checked = this.aiValue
        })
        this.persist()
        this.render()
    }

    persist() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                billing: this.billingValue,
                ai: this.aiValue,
            }))
        } catch {
            // Private-browsing / quota errors are fine — the page still works.
        }
    }

    render() {
        this.billingButtonTargets.forEach((btn) => {
            const matches = btn.dataset.billing === this.billingValue
            btn.classList.toggle('btn-active', matches)
            btn.setAttribute('aria-pressed', matches ? 'true' : 'false')
        })

        this.cardTargets.forEach((card) => this.renderCard(card))
    }

    renderCard(card) {
        const variant = this.cardVariantKey(card)

        const priceTarget = card.querySelector('[data-pricing-target="price"]')
        if (priceTarget) {
            const price = card.dataset[`price${variant}`]
            if (typeof price === 'string') {
                priceTarget.textContent = price
            }
        }

        const strikeTarget = card.querySelector('[data-pricing-target="strikethrough"]')
        if (strikeTarget) {
            const strike = card.dataset[`strike${variant}`] ?? ''
            if (strike === '') {
                strikeTarget.classList.add('invisible')
                strikeTarget.textContent = ''
            } else {
                strikeTarget.textContent = strike
                strikeTarget.classList.remove('invisible')
            }
        }

        const noteTarget = card.querySelector('[data-pricing-target="billingNote"]')
        if (noteTarget) {
            const note = card.dataset[`note${variant}`]
            if (typeof note === 'string') {
                noteTarget.textContent = note
            }
        }

        const chipTarget = card.querySelector('[data-pricing-target="savingsChip"]')
        if (chipTarget) {
            const chip = card.dataset[`chip${variant}`] ?? ''
            if (chip === '') {
                chipTarget.classList.add('hidden')
                chipTarget.textContent = ''
            } else {
                chipTarget.textContent = chip
                chipTarget.classList.remove('hidden')
            }
        }

        const aiFeature = card.querySelector('[data-pricing-target="aiFeature"]')
        if (aiFeature) {
            aiFeature.toggleAttribute('hidden', !this.aiValue)
        }

        const cta = card.querySelector('[data-pricing-target="cta"]')
        if (cta) {
            const href = cta.dataset[`href${variant}`]
            if (typeof href === 'string' && href !== '') {
                cta.setAttribute('href', href)
            }
            const label = cta.dataset[`label${variant}`]
            if (typeof label === 'string' && label !== '') {
                cta.textContent = label
            }
        }
    }

    cardVariantKey(card) {
        // PascalCase dataset keys: data-price-annual → "Annual",
        // data-price-ai-monthly → "AiMonthly".
        const billing = this.billingValue === 'monthly' ? 'Monthly' : 'Annual'
        if (!this.aiValue) {
            return billing
        }
        if (card.dataset.aiAvailable === 'false') {
            // Free card has no AI variant; fall back to the non-AI prices
            // and let the CTA-href override switch to lead capture.
            return billing
        }

        return 'Ai' + billing
    }
}
