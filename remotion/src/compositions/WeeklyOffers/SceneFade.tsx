import { AbsoluteFill, interpolate, useCurrentFrame } from "remotion";

// Keep the outgoing scene underneath while the whole incoming scene appears.
export const SceneFade: React.FC<React.PropsWithChildren<{ frames: number; enabled?: boolean }>> = ({
  frames,
  children,
  enabled = true,
}) => {
  const frame = useCurrentFrame();
  return (
    <AbsoluteFill style={{ opacity: !enabled ? 1 : interpolate(frame, [0, frames - 1], [0, 1], {
      extrapolateLeft: "clamp",
      extrapolateRight: "clamp",
    }) }}>
      {children}
    </AbsoluteFill>
  );
};
