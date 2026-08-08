import {
    Eye,
    Heart,
    MessageCircle,
    Share2,
    Trophy,
} from '@lucide/vue';
import type { Component } from 'vue';

const METRIC_ICONS: Record<string, Component> = {
    views: Eye,
    likes: Heart,
    comments: MessageCircle,
    shares: Share2,
    score: Trophy,
};

export function metricIcon(key: string): Component {
    return METRIC_ICONS[key] ?? Eye;
}
