import Supercluster from 'supercluster';
import type { MapView } from './types';

export interface ClusterInput {
    id: number;
    lat: number;
    lng: number;
}

export type ClusterItem =
    | { kind: 'cluster'; clusterId: number; count: number; lat: number; lng: number }
    | { kind: 'point'; id: number; lat: number; lng: number };

/**
 * Agrupa las sedes cercanas según el acercamiento. Es el mismo cálculo con cualquier motor: el
 * motor solo pinta lo que sale de aquí.
 */
export class StoreClusters {
    private index = new Supercluster<{ id: number }>({ radius: 56, maxZoom: 16 });

    load(points: ClusterInput[]): void {
        this.index = new Supercluster<{ id: number }>({ radius: 56, maxZoom: 16 });
        this.index.load(
            points.map((point) => ({
                type: 'Feature' as const,
                properties: { id: point.id },
                geometry: { type: 'Point' as const, coordinates: [point.lng, point.lat] },
            })),
        );
    }

    at(view: MapView): ClusterItem[] {
        const { bounds } = view;
        const box: [number, number, number, number] = [Math.max(-180, bounds.west), Math.max(-85, bounds.south), Math.min(180, bounds.east), Math.min(85, bounds.north)];

        return this.index.getClusters(box, Math.floor(view.zoom)).map((feature) => {
            const [lng, lat] = feature.geometry.coordinates;
            const properties = feature.properties as { cluster?: boolean; cluster_id?: number; point_count?: number; id?: number };

            return properties.cluster
                ? { kind: 'cluster' as const, clusterId: properties.cluster_id ?? 0, count: properties.point_count ?? 0, lat, lng }
                : { kind: 'point' as const, id: properties.id ?? 0, lat, lng };
        });
    }

    expansionZoom(clusterId: number): number {
        return Math.min(this.index.getClusterExpansionZoom(clusterId), 18);
    }
}
