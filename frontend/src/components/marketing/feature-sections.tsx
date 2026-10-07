import type { LucideIcon } from "lucide-react";
import {
  Clock,
  Lock,
  MessageCircle,
  Play,
  ShieldCheck,
  ShoppingCart,
  Smartphone,
} from "lucide-react";
import {
  Card,
  CardBody,
  CardTitle,
  Container,
  Eyebrow,
  GRID_2,
  GRID_3,
  IconTile,
  Lead,
  Section,
  SectionTitle,
} from "@/components/marketing/primitives";

/** `.vid` / `.play` — the 16:9 video well with its play affordance. */
function VideoWell({ label }: { label: string }) {
  return (
    <div
      className="mt-5 flex aspect-video flex-col items-center justify-center gap-3 rounded-[14px] text-[14px]"
      style={{ background: "#101827", color: "#cbd5e1" }}
    >
      <div className="flex size-16 items-center justify-center rounded-full bg-white">
        <Play width="22" height="22" className="fill-primary text-primary" />
      </div>
      {label}
    </div>
  );
}

const TOURS = [
  {
    title: "Watch a video of the clinic portal",
    body: "Take a tour of the doctor’s side of the platform. You will see how easy it is to see all your patients’ daily logs and how quickly you can review them all in one place.",
    label: "[ Clinic portal video ]",
  },
  {
    title: "Watch a video of the patient portal",
    body: "Take a tour of the patient side of the platform. Watch how easy it is for them to log all their progress on a daily basis and how using the app will keep them motivated and engaged.",
    label: "[ Patient portal video ]",
  },
];

export function VideoTours() {
  return (
    <Section>
      <Container>
        <Eyebrow>See it in action</Eyebrow>
        <SectionTitle>See how ChiroThin Tracker works for you</SectionTitle>
        <Lead>
          Watch these short videos to learn how ChiroThin Tracker helps you stay
          on track, achieve your goals, and live healthier.
        </Lead>

        <div className={`mt-12 grid gap-5 ${GRID_2}`}>
          {TOURS.map((tour) => (
            <Card key={tour.title}>
              <CardTitle>{tour.title}</CardTitle>
              <CardBody>{tour.body}</CardBody>
              <VideoWell label={tour.label} />
            </Card>
          ))}
        </div>
      </Container>
    </Section>
  );
}

const FEATURES: { icon: LucideIcon; title: string; body: string }[] = [
  {
    icon: ShieldCheck,
    title: "Designed by ChiroThin® doctors",
    body: "Conceptualized, designed, developed and tested by ChiroThin® doctors for licensed ChiroThin® offices.",
  },
  {
    icon: Lock,
    title: "HIPAA-compliant and secure",
    body: "Your data is protected with industry-leading security and compliance standards.",
  },
  {
    icon: Clock,
    title: "Feature-rich and time-saving",
    body: "Automated patient tracking, secure messaging and video consultations, all on one platform.",
  },
  {
    icon: ShoppingCart,
    title: "Built-in e-commerce",
    body: "Sell supplements, services and more directly through the platform with easy payment integration.",
  },
  {
    icon: MessageCircle,
    title: "Engagement and retention",
    body: "Daily tracking, automated messaging and interactive tools keep patients motivated.",
  },
  {
    icon: Smartphone,
    title: "24/7 access, anywhere",
    body: "Manage your clinic and support your patients from any device, at any time.",
  },
];

export function WhyTracker() {
  return (
    <Section tone="paper">
      <Container>
        <Eyebrow>Why ChiroThin Tracker</Eyebrow>
        <SectionTitle>
          The only officially endorsed ChiroThin® tracking software
        </SectionTitle>
        <Lead>Built to save time, boost patient engagement and grow your practice.</Lead>

        <div className={`mt-12 grid gap-5 ${GRID_3}`}>
          {FEATURES.map((feature) => (
            <Card key={feature.title} tone="canvas">
              <IconTile>
                <feature.icon width="22" height="22" aria-hidden />
              </IconTile>
              <CardTitle>{feature.title}</CardTitle>
              <CardBody>{feature.body}</CardBody>
            </Card>
          ))}
        </div>
      </Container>
    </Section>
  );
}