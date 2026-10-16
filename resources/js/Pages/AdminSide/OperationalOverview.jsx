import React from 'react';
import { Box, Typography } from '@mui/material';
import StorageOutlinedIcon from '@mui/icons-material/StorageOutlined';
import TrackChangesOutlinedIcon from '@mui/icons-material/TrackChangesOutlined';
import CloudOutlinedIcon from '@mui/icons-material/CloudOutlined';

const formatDate = (value) => {
    if (!value) return 'Awaiting measurement';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Date unavailable' : new Intl.DateTimeFormat(undefined, {
        day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
    }).format(date);
};

function Metric({ icon: Icon, label, value, detail }) {
    return <Box sx={{ minWidth: 0, p: 2, bgcolor: 'rgba(255,255,255,.025)', border: '1px solid rgba(255,255,255,.065)', borderRadius: '12px' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, color: '#9ba7a4', mb: 1.2 }}>
            <Icon sx={{ fontSize: 18, color: '#78b49a' }} />
            <Typography sx={{ fontSize: 12, fontWeight: 500 }}>{label}</Typography>
        </Box>
        <Typography sx={{ color: '#eef4f1', fontSize: 21, fontWeight: 600, lineHeight: 1.3, overflowWrap: 'anywhere' }}>{value}</Typography>
        <Typography sx={{ color: '#97a29e', fontSize: 12, mt: .8, lineHeight: 1.6, overflowWrap: 'anywhere' }}>{detail}</Typography>
    </Box>;
}

export default function OperationalOverview({ stats }) {
    const metrics = stats.operational_metrics;
    if (!metrics) return null;
    const source = metrics.source_storage_scope;
    const generated = metrics.generated_storage_observation;
    const pending = Math.max(0, stats.total_videos - stats.completed_videos - stats.processing_videos - stats.failed_videos);
    const statuses = [
        ['Completed', stats.completed_videos, '#7bdbab'],
        ['Processing', stats.processing_videos, '#90bcf8'],
        ['Pending', pending, '#e3c383'],
        ['Failed', stats.failed_videos, '#f39a9a'],
    ];
    return <Box component="section" aria-label="Storage and processing overview" sx={{
        p: { xs: 2, md: 2.5 }, borderRadius: '16px', bgcolor: '#1b2220', border: '1px solid rgba(123,219,171,.12)',
    }}>
        <Box sx={{ display: 'flex', alignItems: { xs: 'flex-start', md: 'center' }, justifyContent: 'space-between', gap: 2, flexWrap: 'wrap', mb: 2 }}>
            <Box>
                <Typography component="h2" sx={{ color: '#edf4f0', fontSize: 16, fontWeight: 600 }}>Storage & processing overview</Typography>
                <Typography sx={{ color: '#9ba7a4', fontSize: 12, mt: .5 }}>Current processing counts · Dated storage and index measurements</Typography>
            </Box>
            <Box sx={{ display: 'flex', gap: .8, flexWrap: 'wrap' }}>
                {statuses.map(([label, count, color]) => <Box key={label} sx={{ display: 'flex', alignItems: 'center', gap: .75, px: 1.2, py: .75, borderRadius: '8px', bgcolor: 'rgba(255,255,255,.035)', border: '1px solid rgba(255,255,255,.06)' }}>
                    <Box sx={{ width: 6, height: 6, borderRadius: '50%', bgcolor: color }} />
                    <Typography sx={{ fontSize: 12, color: '#b9c4bf' }}>{label}</Typography>
                    <Typography sx={{ fontSize: 12, fontWeight: 600, color, fontVariantNumeric: 'tabular-nums' }}>{count}</Typography>
                </Box>)}
            </Box>
        </Box>
        <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, minmax(0, 1fr))', lg: 'repeat(4, minmax(0, 1fr))' }, gap: 1.5 }}>
            <Metric icon={StorageOutlinedIcon} label="Registered storage estimate"
                value={`${(metrics.registered_storage_estimated_bytes / 1073741824).toFixed(2)} GiB`}
                detail="Based on stored video sizes; rounded estimate." />
            <Metric icon={StorageOutlinedIcon} label="Local generated media"
                value={metrics.generated_storage_bytes != null ? `${(metrics.generated_storage_bytes / 1e9).toFixed(3)} GB` : 'Not measured'}
                detail={generated ? `${generated.files ?? 0} files · ${formatDate(generated.observed_at_utc)}` : 'Run the media measurement to update.'} />
            <Metric icon={CloudOutlinedIcon} label="Source inventory scope"
                value={source?.folder === '/' ? 'Dropbox integration root' : source?.folder || 'Not inventoried'}
                detail={source ? `Observed ${formatDate(source.finished_at_utc)}` : 'Awaiting a source inventory.'} />
            <Metric icon={TrackChangesOutlinedIcon} label="Videos with index passages"
                value={metrics.index_observation?.videos_with_points ?? 'Not measured'}
                detail="Observed in the index audit; not a live coverage check." />
        </Box>
        <Typography sx={{ color: '#9ba7a4', fontSize: 12, mt: 1.75, lineHeight: 1.7 }}>
            Counts overlap. Completed embedding records do not confirm current index coverage. Remote generated/proxy storage is not inventoried.
        </Typography>
    </Box>;
}
