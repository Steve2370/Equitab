<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import AdminPageHeader from "@/Components/Admin/AdminPageHeader.vue";
import {
    Users,
    FolderOpen,
    CreditCard,
    AlertTriangle,
    ArrowUpRight,
    ShieldCheck,
} from "lucide-vue-next";
import { formatCad } from "@/config/servicePresentation";
defineProps<{
    stats: {
        totalUsers: number;
        totalGroups: number;
        activeGroups: number;
        totalPayments: number;
        totalRevenue: number;
        equitabEarnings: number;
        openDisputes: number;
        verifiedUsers: number;
    };
}>();
</script>
<template>
    <Head title="Vue d’ensemble — Admin EquitAb" />
    <AdminLayout>
        <AdminPageHeader
            title="Le partage, en perspective."
            description="Les membres, les groupes et les paiements. Tous vos repères pour accompagner la communauté."
            section="LE QUOTIDIEN / VUE D’ENSEMBLE"
        >
            <Link href="/admin/disputes" class="eq-button eq-button-secondary"
                >Voir les litiges <ArrowUpRight :size="16"
            /></Link>
        </AdminPageHeader>
        <div class="admin-metrics">
            <Link href="/admin/users" class="admin-metric">
                <div><span>Utilisateurs</span><Users :size="20" /></div>
                <strong>{{ stats.totalUsers }}</strong>
                <p>{{ stats.verifiedUsers }} identités vérifiées</p>
                <ArrowUpRight class="metric-arrow" :size="18" />
            </Link>
            <Link href="/admin/groups" class="admin-metric">
                <div><span>Groupes actifs</span><FolderOpen :size="20" /></div>
                <strong>{{ stats.activeGroups }}</strong>
                <p>{{ stats.totalGroups }} groupes au total</p>
                <ArrowUpRight class="metric-arrow" :size="18" />
            </Link>
            <Link href="/admin/payments" class="admin-metric">
                <div>
                    <span>Paiements terminés</span><CreditCard :size="20" />
                </div>
                <strong>{{ stats.totalPayments }}</strong>
                <p>Transactions confirmées</p>
                <ArrowUpRight class="metric-arrow" :size="18" />
            </Link>
            <Link href="/admin/disputes" class="admin-metric metric-attention">
                <div>
                    <span>Litiges ouverts</span><AlertTriangle :size="20" />
                </div>
                <strong>{{ stats.openDisputes }}</strong>
                <p>
                    {{
                        stats.openDisputes
                            ? "À examiner"
                            : "Aucun litige ouvert"
                    }}
                </p>
                <ArrowUpRight class="metric-arrow" :size="18" />
            </Link>
        </div>
        <section class="admin-finance" aria-labelledby="finance-title">
            <div class="finance-volume">
                <p class="eq-eyebrow">LES CHIFFRES, AU CLAIR</p>
                <h2 id="finance-title">Volume total des paiements</h2>
                <p class="finance-number">
                    {{ formatCad(stats.totalRevenue) }}
                </p>
                <span>CAD · paiements terminés, toutes périodes</span>
                <Link href="/admin/payments"
                    >Consulter les transactions <ArrowUpRight :size="18"
                /></Link>
            </div>
            <div class="finance-earnings">
                <span class="finance-icon"><CreditCard :size="23" /></span>
                <h3>Commissions EquitAb</h3>
                <p>{{ formatCad(stats.equitabEarnings) }}</p>
                <span
                    >CAD · montants enregistrés sur les paiements
                    terminés.</span
                >
                <div class="finance-note">
                    <ShieldCheck :size="18" /><span
                        >Une lecture des montants réels, sans projection de
                        revenus.</span
                    >
                </div>
            </div>
        </section>
        <section class="admin-shortcuts" aria-labelledby="shortcuts-title">
            <div>
                <p class="eq-eyebrow text-eq-green">
                    ACCOMPAGNER LA COMMUNAUTÉ
                </p>
                <h2 id="shortcuts-title">L’essentiel, à portée de main.</h2>
            </div>
            <Link href="/admin/users"
                ><Users :size="20" /><span
                    ><strong>Les membres</strong
                    ><small>Identités, accès et comptes</small></span
                ><ArrowUpRight :size="18"
            /></Link>
            <Link href="/admin/messages"
                ><span class="shortcut-symbol">@</span
                ><span
                    ><strong>Prendre contact</strong
                    ><small>Écrire aux utilisateurs</small></span
                ><ArrowUpRight :size="18"
            /></Link>
        </section>
    </AdminLayout>
</template>
<style scoped>
.admin-metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}
.admin-metric {
    position: relative;
    padding: 24px 22px;
    border: 1px solid var(--color-eq-line);
    border-radius: 22px;
    background: white;
    transition: border-color 0.2s;
    min-width: 0;
}
.admin-metric:hover {
    border-color: #35af7f;
}
.admin-metric > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    color: #65756d;
    font-size: 11px;
}
.admin-metric > div svg {
    color: #187a57;
    flex: none;
}
.admin-metric strong {
    display: block;
    font-size: 38px;
    font-weight: 550;
    letter-spacing: -0.055em;
    margin-top: 25px;
    line-height: 1.15;
}
.admin-metric p {
    font-size: 10px;
    color: #65756d;
    margin-top: 10px;
    padding-right: 20px;
}
.metric-arrow {
    position: absolute;
    bottom: 24px;
    right: 20px;
    color: #187a57;
}
.metric-attention {
    background: #fff9ed;
    border-color: #e7deca;
}
.metric-attention > div svg,
.metric-attention .metric-arrow {
    color: #94601e;
}
.admin-finance {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    margin-top: 24px;
    border-radius: 26px;
    overflow: hidden;
    border: 1px solid var(--color-eq-line);
}
.finance-volume {
    background: #164d3a;
    color: white;
    padding: 36px;
}
.finance-volume .eq-eyebrow {
    color: #9ee4c1;
    font-size: 9px;
}
.finance-volume h2 {
    margin-top: 25px;
    font-size: 14px;
    font-weight: 400;
}
.finance-number {
    font-size: clamp(32px, 4vw, 55px);
    letter-spacing: -0.06em;
    line-height: 1.1;
    margin-block: 14px;
    overflow-wrap: anywhere;
}
.finance-volume > span {
    font-size: 11px;
    color: #b6dac7;
}
.finance-volume a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid #8ad3ad40;
    margin-top: 34px;
    padding-top: 24px;
    font-size: 12px;
}
.finance-earnings {
    padding: 32px;
    background: #e7f3ed;
}
.finance-icon {
    display: grid;
    place-items: center;
    width: 48px;
    height: 48px;
    border: 1px solid #b9d9c6;
    border-radius: 15px;
    color: #187a57;
}
.finance-earnings h3 {
    font-size: 13px;
    margin-top: 24px;
}
.finance-earnings > p {
    font-size: 35px;
    letter-spacing: -0.05em;
    margin-block: 12px;
    color: #126344;
    overflow-wrap: anywhere;
}
.finance-earnings > span:not(.finance-icon) {
    font-size: 11px;
    line-height: 1.8;
    display: block;
    color: #526f60;
}
.finance-note {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 10px;
    line-height: 1.7;
    margin-top: 24px;
    color: #526f60;
}
.finance-note svg {
    flex: none;
}
.admin-shortcuts {
    display: grid;
    grid-template-columns: 1.1fr 1fr 1fr;
    align-items: center;
    gap: 24px;
    margin-top: 38px;
    padding-top: 32px;
    border-top: 1px solid var(--color-eq-line);
}
.admin-shortcuts .eq-eyebrow {
    font-size: 9px;
}
.admin-shortcuts h2 {
    font-size: 22px;
    letter-spacing: -0.045em;
    margin-top: 13px;
    line-height: 1.3;
}
.admin-shortcuts > a {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 80px;
    font-size: 12px;
}
.admin-shortcuts > a > span:not(.shortcut-symbol) {
    flex: 1;
}
.admin-shortcuts strong {
    display: block;
    font-weight: 600;
}
.admin-shortcuts small {
    display: block;
    color: #65756d;
    margin-top: 7px;
}
.shortcut-symbol {
    font-size: 24px;
}
@media (max-width: 1250px) {
    .admin-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .admin-shortcuts {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .admin-shortcuts > div {
        grid-column: 1/-1;
    }
}
@media (max-width: 640px) {
    .admin-metric {
        padding: 20px 16px;
    }
    .admin-metric > div {
        flex-direction: column-reverse;
        align-items: start;
    }
    .admin-metric strong {
        font-size: 32px;
        margin-top: 18px;
    }
    .metric-arrow {
        display: none;
    }
    .admin-metric p {
        padding: 0;
        line-height: 1.6;
    }
    .admin-finance {
        grid-template-columns: minmax(0, 1fr);
    }
    .finance-volume,
    .finance-earnings {
        padding: 26px 22px;
    }
    .admin-shortcuts {
        grid-template-columns: minmax(0, 1fr);
        gap: 10px;
    }
}
</style>
