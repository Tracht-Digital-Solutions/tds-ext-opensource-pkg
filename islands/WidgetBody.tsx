import { SummaryMetric } from "@tracht-digital-solutions/tds-shared/components";
import { formatCents } from "@tracht-digital-solutions/tds-shared/format";

interface Summary {
  projects: number;
  donations: { currency: string; count: number; total: number; last30: number }[];
}

/** "Spenden" widget body — paid donations in the last 30 days, first currency. */
export default function WidgetBody() {
  return (
    <SummaryMetric<Summary>
      endpoint="/opensource/summary"
      value={(d) => {
        const first = d.donations[0];
        return first ? formatCents(first.last30, { currency: first.currency }) : formatCents(0);
      }}
      caption={(d) => {
        const first = d.donations[0];
        return `letzte 30 Tage · ${first ? first.count : 0} Spenden insgesamt · ${d.projects} Projekte`;
      }}
    />
  );
}
